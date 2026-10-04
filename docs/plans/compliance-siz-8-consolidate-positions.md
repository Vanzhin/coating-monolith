# Учёт СИЗ Д8 — консолидация позиций в «Действующих позициях» (вариант A)

**Цель:** на странице акта выдачи (режим списания) показывать «Действующие позиции» **по позиции**, а не по факту: одна строка на `obligationKey`, светофор по **сумме на руках против нормы**, разворот-аккордеон с разбивкой по актам (трассировка item→акт). Чинит ложный красный, когда позиция выдана несколькими актами (напр. Перчатки: норма 22, факты 12+10 на руках = 22 → сейчас два красных, должна быть одна зелёная).

**Соседние планы:** `compliance-siz-6-quantity-writeoff.md` (порции), `compliance-siz-7-personal-obligations.md`. Макет (вариант A) согласован: https://claude.ai/code/artifact/25e8be49-ad65-4bc5-ac38-7cae7c60668e

## Решённая модель

- **Группировка по позиции.** «Действующие позиции» строятся по `obligationKey` (не по факту). Агрегат «на руках» = Σ `heldAmount` фактов позиции.
- **Единый бейдж «на руках / норма»** у ВСЕХ позиций: `{Σheld} / {norm} {unit}` (если нормы нет — просто `{Σheld} {unit}`). Цвет — `heldTone(Σheld, norm)` (уже есть: <норма красный, =норма зелёный, >норма голубой, нет нормы серый).
- **Разворот (аккордеон)** только при >1 факте: под-строки по фактам — дата выдачи, на руках по факту, ссылка на акт выдачи (скан), и пометка «списано N (акт списания № …)», если по факту были списания. Одиночные позиции — без разворота.
- Область изменения — ТОЛЬКО обзор на странице выдачи (режим списания). Страница самого акта списания (`ShowAction`/`act.html.twig`), где выбирают кол-во по каждому факту, — НЕ трогаем (там по-фактно осмысленно).

## Global Constraints

- Светофор — по агрегату позиции, не по факту. Проекция уже считает агрегат (`held_quantity`), но здесь считаем из фактов (тот же источник, что список), чтобы иметь разбивку.
- Бейдж единый для материальных позиций списка (все они с нормой-количеством). Нематериальные в этот список не попадают (фильтр `null === quantity`).
- Тонкий контроллер: сбор позиций — в `IssueAction` (оркестрация чтения), без бизнес-логики; домен не трогаем (данные уже даёт `recordsForRequirement`/`getWriteOffActs`/`itemsOfWriteOffAct`).
- Аккордеон — Bootstrap collapse (как в отчётах `fill.html.twig`), без нового JS.
- Стили — только существующие классы (бейджи `text-bg-*`, `bg-body`/`bg-body-tertiary`, `rounded-3`); новых не вводим. Мобайл — строки переносятся как сейчас.
- `yarn dev` НЕ нужен (только Twig/PHP). Dev-кэш очистить после Twig.
- Коммит — по явному апруву.

## Review Focus

- Позиция, выданная несколькими актами с суммой = норме → одна зелёная строка (регресс-тест на Перчатки 12+10/22).
- Позиция с суммой < нормы → красная; > нормы → голубая.
- Факт без `documentId` (старые данные) → строка разбивки без ссылки на акт, не падает.
- Частично списанный факт → в разбивке «на руках» = выдано − списано, и пометка про акт списания.
- Одиночная позиция (1 факт) → без аккордеона, бейдж тот же «на руках / норма».

---

## Task 1: IssueAction — собирать позиции (writeoff-режим), а не факты

**Files:**
- Modify: `app/src/Compliance/Infrastructure/Controller/Fulfillment/IssueAction.php`

**Сейчас:** в writeoff-ветке `renderForm` цикл по `recordsForRequirement` кладёт по строке НА ФАКТ в `$rows` (label/meta/date/amount/unit/wear/held/tone). Надо заменить на сбор по позициям.

- [ ] **Шаг 1.1: построить карту «фактов списания» recordId → [{actNumber, qty}]**
Для пометки «списано актом» в разбивке. До цикла по фактам:
```php
$writeOffsByRecord = []; // recordId => list<array{actNumber: ?string, qty: float}>
foreach ($profileCompliance->getWriteOffActs() as $act) {
    if ($act->requirementId() !== $requirementId || !$act->isSigned()) {
        continue;
    }
    foreach ($profileCompliance->itemsOfWriteOffAct($act->getId()) as $portion) {
        $writeOffsByRecord[$portion->recordId()][] = ['actNumber' => $act->actNumber(), 'qty' => $portion->quantity()];
    }
}
```

- [ ] **Шаг 1.2: сгруппировать факты по позиции в `$positions`**
Заменить нынешний `$rows = []; foreach (...) { $rows[] = [...per-fact...]; }` на:
```php
$positions = []; // obligationKey => {label, meta, unit, norm, held, facts[]}
foreach ($profileCompliance?->recordsForRequirement($requirementId) ?? [] as $record) {
    if (null === $record->quantity() || $record->heldAmount() <= 0.0) {
        continue; // нематериальный или полностью списан
    }
    $key = $record->obligationKey();
    $positions[$key] ??= [
        'label' => $profileCompliance?->obligationLabelOf($key),
        'meta' => $cadenceByKey[$key] ?? '',
        'unit' => $record->quantity()->unit->title(),
        'norm' => $normByKey[$key] ?? null,
        'held' => 0.0,
        'facts' => [],
    ];
    $held = $record->heldAmount();
    $positions[$key]['held'] += $held;
    $writeOffNote = '';
    foreach ($writeOffsByRecord[$record->getId()] ?? [] as $w) {
        $writeOffNote .= sprintf('списано %s%s; ', AmountFormatter::trimmed($w['qty']), null !== $w['actNumber'] && '' !== $w['actNumber'] ? ' (акт № '.$w['actNumber'].')' : '');
    }
    $positions[$key]['facts'][] = [
        'date' => $record->fulfilledAt()->format('d.m.Y'),
        'held' => AmountFormatter::trimmed($held),
        'documentId' => $record->documentId(), // ?string — ссылка на акт выдачи (скан)
        'writeOffNote' => trim($writeOffNote, '; '),
    ];
}
```

- [ ] **Шаг 1.3: финализировать позиции (цвет по сумме, формат бейджа)**
```php
$rows = [];
foreach ($positions as $p) {
    $norm = $p['norm'];
    $rows[] = [
        'label' => $p['label'],
        'meta' => trim(($p['meta'] ?? '').(null !== $norm ? ' · норма '.AmountFormatter::trimmed($norm).' '.$p['unit'] : '')),
        'unit' => $p['unit'],
        'held' => AmountFormatter::trimmed($p['held']),
        'norm' => null !== $norm ? AmountFormatter::trimmed($norm) : null,
        'tone' => $this->heldTone($p['held'], $norm),
        'facts' => $p['facts'],
    ];
}
```
`$rows` остаётся именем переменной, которую уже ждёт шаблон (меняем только форму элементов). `AmountFormatter`, `$cadenceByKey`, `$normByKey`, `heldTone` — уже есть в методе. Нужен геттер `FulfillmentRecord::documentId()` — проверить, что он есть (поле `document_id` в ORM есть; если геттера нет — добавить тривиальный).

- [ ] **Шаг 1.4: phpstan-типы** — для `$rows`/`$positions` добавить `@var`/докблок, если phpstan ругнётся на `mixed`/array-shape.

---

## Task 2: issue.html.twig — рендер позиций с аккордеоном (writeoff-режим)

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/person/issue.html.twig`

**Сейчас:** в `{% if writeoff %}` секции `#sec-items` цикл `{% for row in rows %}` рисует `_item_card` с «На руках» (per-fact) + disabled-поля даты/кол-ва. Заменить на строки-позиции.

- [ ] **Шаг 2.1:** заменить блок `{% for row in rows %}…{% endfor %}` на:
```twig
{% for row in rows %}
    {% set rid = 'wo-pos-' ~ loop.index0 %}
    {% set hasBreakdown = row.facts|length > 1 %}
    <div class="p-3 mb-2 rounded-3 bg-body">
        <div class="d-flex flex-wrap align-items-center gap-2{% if hasBreakdown %} report-block-toggle{% endif %}"
             {% if hasBreakdown %}role="button" data-bs-toggle="collapse" data-bs-target="#{{ rid }}" aria-expanded="false"{% endif %}>
            <div class="me-auto">
                <div class="fw-semibold">{{ row.label }}{% if hasBreakdown %} <span class="text-body-secondary small fw-normal">· {{ row.facts|length }} акта</span>{% endif %}</div>
                {% if row.meta %}<div class="text-body-secondary small">{{ row.meta }}</div>{% endif %}
            </div>
            <div class="text-end">
                <div class="small text-body-secondary mb-1">На руках</div>
                <span class="badge text-bg-{{ row.tone }} fw-normal">{{ row.held }}{% if row.norm %} / {{ row.norm }}{% endif %} {{ row.unit }}</span>
            </div>
            {% if hasBreakdown %}<i class="bi bi-chevron-down ms-1 text-body-secondary"></i>{% endif %}
        </div>
        {% if hasBreakdown %}
            <div class="collapse mt-2" id="{{ rid }}">
                {% for f in row.facts %}{{ _self_wo_fact(f) }}{% endfor %}
            </div>
        {% endif %}
    </div>
{% endfor %}
```
(Для одиночной позиции — та же шапка без toggle/разворота: `facts|length == 1`.)

- [ ] **Шаг 2.2:** под-строка факта — через макрос (не дублируем разметку). Определить макрос в этом же файле (как `personal_row` в Д7) и звать импортом по пути (`_self`-импорт при `extends` не работает — грабли из Д7):
```twig
{% macro wo_fact(f) %}
    <div class="p-2 mb-1 rounded-3 bg-body-tertiary d-flex flex-wrap align-items-center gap-2">
        <div class="small">
            Выдан: {% if f.documentId %}<a href="{{ path('app_cabinet_compliance_document_download_scan', {profileId: profileId, documentId: f.documentId}) }}">{{ f.date }}</a>{% else %}{{ f.date }}{% endif %}
            {% if f.writeOffNote %}<span class="text-danger"> · {{ f.writeOffNote }}</span>{% endif %}
        </div>
        <div class="ms-auto small text-body-secondary">на руках <span class="fw-semibold text-body">{{ f.held }} {{ row_unit }}</span></div>
    </div>
{% endmacro %}
```
Единицу в под-строке передавать параметром макроса (`wo_fact(f, row.unit)`), чтобы не тянуть из внешнего скоупа. Импорт: `{% import 'admin/compliance/person/issue.html.twig' as wo %}` и `wo.wo_fact(f, row.unit)`.

- [ ] **Шаг 2.3:** проверить, что `profileId` доступен в макросе (в Twig макросы не видят глобальный скоуп) — передавать `profileId` параметром макроса тоже, либо строить ссылку вне макроса. Решение: макрос принимает `(f, unit, profileId)`.

---

## Task 3: Функциональный тест консолидации

**Files:**
- Modify: `app/tests/Functional/Compliance/Infrastructure/Controller/CardDownloadControllerTest.php` (рядом с `test_write_off_act_pages_render`)

- [ ] **Шаг 3.1:** сценарий: выдать позицию ДВУМЯ актами так, что сумма = норме (норма N, выдать N двумя картами или одной картой + повторной выдачей), затем GET issue-страницы (режим списания) и проверить:
  - позиция встречается РОВНО один раз (один заголовок);
  - бейдж зелёный (`text-bg-success`) — агрегат = норме;
  - есть разворот `.collapse` с двумя под-строками (по числу актов).
Использовать хелперы `enrollCompliance`/`issueCard`; для второй выдачи — ещё один `FormDraft`+`SignDraft` по тому же требованию (или повторная выдача после частичного списания — как в `WriteOffFlowTest`).

- [ ] **Шаг 3.2:** регресс на цвет: собрать дефицит (сумма < нормы) → бейдж `text-bg-danger`.

---

## Task 4: Гейт + смоук

- [ ] **Шаг 4.1:** `./run check` — зелёный.
- [ ] **Шаг 4.2:** dev-кэш очистить (Twig менялся). Браузер: страница выдачи действующей карточки с Перчатками (норма 22, факты 12+10) → одна зелёная строка «22 / 22 пары», разворот показывает оба акта с датами/ссылками и пометкой списания.

## Что НЕ трогаем

- Страница акта списания (`ShowAction`/`act.html.twig`) — там выбор кол-ва по каждому факту, по-фактно осмысленно.
- Домен, проекция, пересчёт — только чтение-сборка в контроллере.
- Раздел «акты профиля» (сводный список актов человека) — отдельная задача (Д9), сейчас не делаем.

## Выбор исполнения

Bounded (1 контроллер + 1 шаблон + тест), дизайн согласован макетом. Нативно по шагам, финальное ревью — на выходе.
