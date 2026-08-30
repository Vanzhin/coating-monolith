# Деплой 5 «Быстрый просмотр»: bottom-sheet на мобиле / модалка на десктопе

> **Для исполнителя:** пошагово, проверка в браузере (обе темы, мобайл+десктоп). «Тест» = сборка (`yarn dev`) + `lint:twig` фрагментов + клик по карточке → превью. PHP-тесты не гоняем (фронт).

**Цель:** превью покрытия и системы сделать современным: на мобиле — bottom-sheet (выезжает снизу, grabber, скругление сверху, почти на весь экран), на десктопе — центрированная модалка. Характеристики — key-value списком (не таблицей), матрица высыхания компактной, химстойкость — сворачиваемой секцией.

**Архитектура:** механизм не меняем — фрагменты (`_coating_preview`, `_coating_system_preview`) остаются Bootstrap-модалками, грузятся в `<body>` через `openFragmentModal`. Добавляем CSS-класс `.modal-sheet` на `.modal-dialog`: на `<lg` — bottom-sheet, на `≥lg` — обычная центрированная modal-lg. Контент фрагментов переверстываем на токены/новые паттерны, сохраняя контракты (id модалки, вложенный coating-preview-loader, deep-link подсветки вещества, макросы `coating_time_matrix`).

**Tech Stack:** Twig-фрагменты, Bootstrap 5.3 modal, Stimulus (coating-preview-loader/entity-preview — без изменений), CSS-токены.

**Spec:** `docs/plans/frontend-redesign-design.md` (раздел 6.3). Референс: `.superpowers/brainstorm/*/content/quickview-sheet.html`, `quickview-chem.html`.

## Global Constraints

- Механизм превью (загрузка фрагмента в модалку body, стек, ESC/клик-вне) НЕ трогаем — только CSS модалки + контент фрагментов.
- Сохранить контракты: id `coatingPreview-{{id}}` / `coatingSystemPreview-{{id}}`; на системной модалке `data-controller="coating-preview-loader"` (вложенное превью покрытия по клику на слой); deep-link `data-highlight-substance-id` (подсветка вещества в chem-секции); макрос `coating_time_matrix(coating)`; include `_chem_resistance_section` / `_system_layer_rows`.
- Химстойкость в превью покрытия — сворачиваемая секция (полный разбор «вещество → покрытия» уедет в Деплой 6 «Химстойкость»). Если данных нет — секции нет.
- Прогрессивно: без JS модалка всё равно валидный HTML; bottom-sheet — чистый CSS.
- Коммиты — по режиму исполнения (эта сессия — коммитит юзер по явному запросу).

## Карта файлов

- Create: `app/assets/styles/components/preview.css` — `.modal-sheet` (bottom-sheet на мобиле), grabber, `.kv` (key-value), `.matrix` (компактная матрица высыхания), `.disc` (сворачиваемая секция химстойкости).
- Modify: `app/assets/styles/app.css` — импорт `preview.css`.
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_coating_preview.html.twig` — переверстка.
- Modify: `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/_coating_system_preview.html.twig` — переверстка.
- (Не трогаем) `_chem_resistance_section.html.twig`, `_system_layer_rows.html.twig` — переиспользуем как есть внутри новых секций (при необходимости лёгкая правобёртка).

---

## Task 1: CSS — bottom-sheet модалка + key-value + матрица

**Files:**
- Create: `app/assets/styles/components/preview.css`
- Modify: `app/assets/styles/app.css`

- [ ] **Step 1: Создать `preview.css`.**

```css
/* Превью-модалки покрытия/системы: на мобиле — bottom-sheet (выезжает снизу),
   на десктопе — обычная центрированная modal-lg. Класс .modal-sheet — на .modal-dialog. */
@media (max-width: 991.98px) {
    .modal-sheet.modal-dialog {
        position: fixed; left: 0; right: 0; bottom: 0; margin: 0;
        max-width: 100%; width: 100%;
    }
    .modal-sheet .modal-content {
        border-radius: 18px 18px 0 0; border-bottom: 0;
        max-height: 92vh;
    }
    /* Grabber сверху. */
    .modal-sheet .modal-content::before {
        content: ""; position: absolute; top: 8px; left: 50%; transform: translateX(-50%);
        width: 38px; height: 5px; border-radius: 3px; background: var(--bs-border-color);
    }
    .modal-sheet .modal-header { padding-top: 20px; }
    /* Выезд снизу вместо fade-сверху. */
    .modal.fade .modal-sheet { transform: translateY(100%); transition: transform .28s var(--ease-emph, ease); }
    .modal.show .modal-sheet { transform: none; }
}

/* Key-value список характеристик (вместо таблицы). */
.kv { background: var(--sunken); border: 1px solid var(--bs-border-color); border-radius: 12px; overflow: hidden; }
.kv .row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 13px; border-bottom: 1px solid var(--bs-border-color); margin: 0; }
.kv .row:last-child { border-bottom: 0; }
.kv .k { font-size: 13px; color: var(--bs-secondary-color); }
.kv .v { font-size: 13.5px; font-weight: 600; text-align: right; }
.kv .v .u { color: var(--faint, var(--bs-secondary-color)); font-weight: 500; font-size: 12px; margin-left: 3px; }

/* Компактная матрица высыхания (гор. скролл, липкий первый столбец). */
.pv-matrix-wrap { overflow-x: auto; border: 1px solid var(--bs-border-color); border-radius: 12px; }
.pv-matrix { border-collapse: collapse; width: 100%; font-size: 12.5px; min-width: 340px; }
.pv-matrix th, .pv-matrix td { padding: 9px 10px; text-align: center; white-space: nowrap; border-bottom: 1px solid var(--bs-border-color); }
.pv-matrix thead th { background: var(--sunken); color: var(--faint, var(--bs-secondary-color)); font-weight: 600; }
.pv-matrix tbody th { text-align: left; font-weight: 600; background: var(--surface); position: sticky; left: 0; }
.pv-matrix td.calc { color: var(--faint, var(--bs-secondary-color)); font-style: italic; }
.pv-matrix tr:last-child th, .pv-matrix tr:last-child td { border-bottom: 0; }

/* Сворачиваемая секция (химстойкость). */
.disc { background: var(--sunken); border: 1px solid var(--bs-border-color); border-radius: 12px; overflow: hidden; }
.disc-head { display: flex; align-items: center; gap: 10px; padding: 12px 13px; cursor: pointer; width: 100%; background: none; border: 0; text-align: left; color: var(--bs-body-color); }
.disc-head .disc-title { flex: 1; font-size: 13.5px; font-weight: 650; }
.disc-head .disc-chev { transition: transform .18s var(--ease-emph, ease); color: var(--faint, var(--bs-secondary-color)); }
.disc-head[aria-expanded="true"] .disc-chev { transform: rotate(180deg); }
.disc-body { border-top: 1px solid var(--bs-border-color); padding: 10px 13px; }

/* Заголовок секции превью. */
.pv-sec { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--faint, var(--bs-secondary-color)); margin: 16px 2px 8px; }
```

- [ ] **Step 2: Импорт в `app.css`** (после entity-card): `@import "components/preview.css";`
- [ ] **Step 3: Собрать.** `cd app && yarn dev`.
- [ ] **Step 4: Коммит.** `git commit -m "CSS быстрого просмотра: bottom-sheet модалка на мобиле, key-value и компактная матрица высыхания"`

---

## Task 2: Превью покрытия (фрагмент)

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_coating_preview.html.twig`

**Контракты (сохранить):** `id="coatingPreview-{{ coating.id }}"`, `data-controller="coating-system-preview-loader"` + endpoint (вложенное превью системы), макрос `thermalRange`, `coating_time_matrix(coating)`, include `_chem_resistance_section` c `data-highlight-substance-id`-подсветкой, footer «Редактировать» (canEdit).

- [ ] **Step 1: Переверстать фрагмент.** Структура: `.modal` → `.modal-dialog.modal-sheet.modal-lg.modal-dialog-scrollable` → header (монограм связующего `coating.base` в плитке `.ecard-mono`-стиле + title + `manufacturer.title` + close) → body: теги+описание; «Входит в системы» (chips, expandable-chips — как есть); «Характеристики» key-value (`.kv`): Тип ЛКМ (`baseEnum.title` + iso/gost), Сухой остаток `volumeSolid` об.%, Плотность `massDensity` кг/л, ТСП `dftRange.min–max` (цель `tds_dft`), Диапазон нанесения `applicationMinTemp … dryingMaxTemp`, Сухое тепло/Погружение (`thermalRange`), Блеск `glossEnum.title`, Упаковка `pack` л, Разбавитель `thinner`; «Возможные цвета» — свотчи (`isTintable` → «Колеруемое» + непривычная палитра как на карточке; иначе `possibleColors` свотчами с именем/RAL); «Время высыхания» — `.pv-matrix` из `coating_time_matrix` (курсив = is_calculated, `bi-infinity` при 0, «—» при null); «Химическая стойкость» — `.disc` (Bootstrap collapse: `disc-head` с `data-bs-toggle="collapse"` + count, `disc-body` = include `_chem_resistance_section`), при пустом `chemResistancePage` секции нет. Footer: Редактировать (canEdit) + Закрыть.

Опорные выражения — из текущего фрагмента (макрос `thermalRange`, matrix-цикл, include chem) переносим без изменения данных, меняем только обёртку/классы. Пример матрицы:

```twig
{% set matrix = coating_time_matrix(coating) %}
<div class="pv-sec">Время высыхания</div>
<div class="pv-matrix-wrap">
    <table class="pv-matrix">
        <thead><tr><th>Поверхность</th>{% for t in matrix.columns %}<th>{{ t > 0 ? '+' ~ t : t }}°</th>{% endfor %}</tr></thead>
        <tbody>
            {% for row in matrix.rows %}
                <tr><th>{{ row.label }}</th>
                    {% for t in matrix.columns %}{% set cell = row.values[t] %}
                        <td class="{{ cell.is_calculated ? 'calc' : '' }}">
                            {% if cell.minutes is null %}—{% elseif cell.minutes == 0 %}<i class="bi bi-infinity"></i>{% else %}{{ cell.minutes|duration_minutes_short }}{% endif %}
                        </td>
                    {% endfor %}
                </tr>
            {% endfor %}
        </tbody>
    </table>
</div>
```

Химстойкость сворачиваемо:
```twig
{% if coating.chemResistancePage is not empty %}
    <div class="pv-sec">Стойкость</div>
    <div class="disc">
        <button class="disc-head" type="button" data-bs-toggle="collapse" data-bs-target="#chem-{{ coating.id }}" aria-expanded="false">
            <span class="disc-title">Химическая стойкость</span>
            <i class="bi bi-chevron-down disc-chev"></i>
        </button>
        <div class="collapse disc-body" id="chem-{{ coating.id }}">
            {% include 'admin/coating/coating/_chem_resistance_section.html.twig' with { coating: coating, assessments: coating.chemResistancePage } %}
        </div>
    </div>
{% endif %}
```

- [ ] **Step 2: Собрать + `lint:twig`** фрагмента.
- [ ] **Step 3: Проверить** (мобайл: bottom-sheet с grabber, выезжает снизу; десктоп: центрированная модалка): открыть покрытие из списка и из слоя системы; key-value читается; матрица скроллится; химстойкость раскрывается; deep-link «стойкое к» подсвечивает вещество; «Редактировать» ведёт в форму. Обе темы.
- [ ] **Step 4: Коммит.** `git commit -m "Быстрый просмотр покрытия: bottom-sheet, характеристики списком, компактная матрица высыхания, химстойкость сворачивается"`

---

## Task 3: Превью системы (фрагмент)

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/_coating_system_preview.html.twig`

**Контракты (сохранить):** `id="coatingSystemPreview-{{ system.id }}"`, `data-controller="coating-preview-loader"` + endpoint (вложенное превью покрытия по клику на слой), include `_system_layer_rows` (кликабельные слои), группировка `compliance` по стандарту.

- [ ] **Step 1: Переверстать фрагмент.** Структура: `.modal-dialog.modal-sheet.modal-lg.modal-dialog-scrollable` → header (иконка `bi-layers` в `.ecard-media`-плитке + title + close) → body: key-value (`.kv`): Подложка, Среда, Подготовка (`surfaceTreatmentCode`/description + standard), Суммарная ТСП, Мин. Т нанесения, Макс. Т эксплуатации (сух./погр.), Мин. время нанесения; «Состав» — include `_system_layer_rows` (слои кликабельны → превью покрытия, контракт сохранён); «Соответствие» — группировка по стандарту (`complianceByStandard`) с подписью стандарта + бейджи (стиль как на карточке); «Документы» (если `documentCount`/список есть — вывести с датами/скачиванием, как в текущем фрагменте); «Теги». Footer: Редактировать (canEdit) + Закрыть.

Данные/выражения — из текущего фрагмента (substrate/environment/surfaceTreatment/totalDft/temps/times/compliance/layers/documents) переносим, меняем обёртку на `.kv`/`.pv-sec`/токены.

- [ ] **Step 2: Собрать + `lint:twig`** фрагмента.
- [ ] **Step 3: Проверить** (мобайл bottom-sheet / десктоп модалка): открыть систему из списка; key-value; слои кликабельны и открывают превью покрытия поверх (стек); соответствия сгруппированы; документы/скачивание; обе темы.
- [ ] **Step 4: Коммит.** `git commit -m "Быстрый просмотр системы: bottom-sheet, характеристики списком, кликабельные слои и сгруппированные соответствия"`

---

## Task 4: Финальная верификация

- [ ] **Step 1: Сборка** `cd app && yarn dev` — без ошибок.
- [ ] **Step 2: `lint:twig`** обоих фрагментов — валидны.
- [ ] **Step 3: Свип** (обе темы, мобайл+десктоп): превью покрытия и системы; bottom-sheet на мобиле (grabber, выезд снизу, скролл, закрытие свайпом-вниз не требуется — крестик/клик-вне/ESC), модалка на десктопе; стек (покрытие поверх системы) работает; deep-link подсветки; матрица; химстойкость collapse.
- [ ] **Step 4: Грепы контрактов.** `rtk grep -n "coatingPreview-\|coatingSystemPreview-\|coating-preview-loader\|highlight-substance-id\|coating_time_matrix" src/Shared/Infrastructure/Templates` — контракты на месте.

## Self-review (покрытие спеки, раздел 6.3)

- Bottom-sheet/модалка → Task 1 (CSS) + Task 2/3 (`.modal-sheet`). Key-value характеристики → Task 2/3 (`.kv`). Матрица высыхания компактная → Task 2. Химстойкость сворачиваемая, при отсутствии скрыта → Task 2. Система: кликабельные слои + сгруппированные соответствия → Task 3. Полный «вещество → покрытия» — Деплой 6 (здесь только сводка/collapse).
- Механизм превью и контракты не тронуты (разведка).

## Открытые точки

- Свайп-вниз для закрытия шита — не делаем (закрытие крестиком/клик-вне/ESC уже есть); добавить жест — возможный follow-up.
- Если `_chem_resistance_section` громоздкий даже в collapse — в Деплое 6 заменим на сводку + ссылку в «Химстойкость». Пока — collapse as-is.
