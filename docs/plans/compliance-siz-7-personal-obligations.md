# Учёт СИЗ Д7 — персональные позиции (добавить item в акт помимо нормы)

**Цель:** дать возможность добавить человеку позицию, которой нет в норме должности («очки», разовый инструктаж и т.п.), прямо строкой в акте выдачи. Позиция трекается наравне с норменными (held/статус/срок/списание), но не входит в норму (origin=Personal) и переживает её пересборку.

**Соседние планы:** `compliance-siz-6-quantity-writeoff.md` (списание порциями), `compliance-siz-3-tracking-card.md` (проекция), `compliance-siz-design.md` (§«персональные добавки, origin=Personal»).

## Решённая модель (итог обсуждения)

Персональная позиция — это `TrackedObligation` с:
- `origin = Personal` — не из нормы; `rebuild()` её не создаёт и не сносит (уже так: снос осиротевших трогает только `ORIGIN_NORM`);
- `requirementId = требование акта, в котором её добавили` — это «в каком акте выдаётся», НЕ членство в норме. То есть позиция прикручена к конкретному акту человека, а не к норме;
- `type = тип этого требования/акта` (мономорфен; смешанный тип акт не примет) — назначается на сервере, клиенту не доверяем;
- `cadence = ByManufacturerDoc` («по документам изготовителя») — срок задаётся явной датой при выдаче;
- своя `quantity` (для материального акта).

Срок окончания = `manualDueDate` на факте выдачи. `ObligationDueCalculator::nextDue(ByManufacturerDoc, lastFulfilledAt, manualDueDate)` возвращает ровно `manualDueDate` → `nextDueAt = срок`, статус зелёный до него, после — красный.

Рождается позиция **строкой в акте выдачи** (не отдельным шагом): на подписи акта (`SignDraft`) ad-hoc строки материализуются в персональные обязанности + пишутся факты. Сравнение с нормой уже денормализовано (каждый item несёт свою `quantity`), поэтому трекинг/дашборд/списание работают без изменений.

## Global Constraints

- Сравнение с нормой — локально по `TrackedObligation.quantity` (requirement в рантайме не читается). Персональная позиция несёт свою норму. Ничего в сравнении не меняем.
- `origin` и `requirementId` — РАЗНЫЕ поля, оба уже есть. Никаких nullable/миграций схемы, никакого литерала `personal`.
- Тип персональной позиции = тип требования акта, форсим на сервере (клиентский тип игнорируем).
- Срок окончания для персональной позиции — ОБЯЗАТЕЛЕН (иначе `nextDue=null` → вечно зелёная). Валидация на бэке.
- Бинарные .docx/.xlsx шаблоны НЕ трогаем (позиции и так проецируются как обязанности/факты требования).
- Коммит — только по явному апруву (feedback_no_commits). Гейт — `./run check` в контейнере; тест-БД пересоздать при дрейфе.

## Review Focus (что проверить тестами сверх задач)

- Персональная позиция без срока — отбивается с понятной ошибкой (не создаётся вечно-зелёная).
- Материальная персональная позиция: held растёт на выданное, дефицит до нормы считается от её собственной `quantity`.
- Пересборка нормы требования (правка/удаление норменной позиции) НЕ трогает персональную того же акта.
- Списание персональной позиции идёт тем же актом списания требования (requirementId тот же).
- Нематериальный акт: персональная строка без количества, только наименование + срок.

---

## Task 1: Домен — `addPersonalObligation` + тип акта

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php`
- Test: `app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php`

**Interfaces (Produces):**
- `typeOfRequirement(string $requirementId): ?ComplianceType` — тип обязанностей требования в карточке (мономорфен; первая найденная); нужен, чтобы форсить тип персональной = тип акта.
- `addPersonalObligation(Uuid $id, string $requirementId, string $label, ComplianceType $type, Cadence $cadence, ?Quantity $quantity, string $departmentId): string` — создаёт `TrackedObligation(origin=Personal)` через существующий `putObligation`, возвращает её ключ (`TrackedObligation::keyOf($requirementId, $label)`). Инвариант: позиция с таким ключом ещё не существует в карточке → иначе `AppException('Позиция «…» уже есть в карточке.')`.

- [ ] **Шаг 1.1: тест `typeOfRequirement`**

```php
public function test_type_of_requirement_returns_mono_type(): void
{
    $pc = $this->pcWithGloves(2.0); // материальная позиция требования $this->reqId
    self::assertSame(ComplianceType::Material, $pc->typeOfRequirement($this->reqId));
    self::assertNull($pc->typeOfRequirement('нет-такого'));
}
```

- [ ] **Шаг 1.2: тест `addPersonalObligation` (создаёт personal, дубль отбивает)**

```php
public function test_add_personal_obligation_is_tracked_and_rejects_duplicate(): void
{
    $pc = $this->pcWithGloves(2.0);
    $dep = $pc->getObligations()[0]->departmentId();
    $key = $pc->addPersonalObligation(
        Uuid::v4(), $this->reqId, 'Очки', ComplianceType::Material,
        new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(1.0, Unit::Piece), $dep,
    );
    self::assertSame(TrackedObligation::keyOf($this->reqId, 'Очки'), $key);
    $added = array_values(array_filter($pc->getObligations(), fn ($o) => $o->key() === $key));
    self::assertCount(1, $added);
    self::assertSame('personal', $added[0]->origin());

    $this->expectException(AppException::class); // дубль по тому же ключу
    $pc->addPersonalObligation(Uuid::v4(), $this->reqId, 'очки', ComplianceType::Material, new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(1.0, Unit::Piece), $dep);
}
```
(Примечание: `keyOf` лоуэркейсит label → «Очки» и «очки» дают один ключ, дубль ловится.)

- [ ] **Шаг 1.3: реализация в `ProfileCompliance`**

```php
public function typeOfRequirement(string $requirementId): ?ComplianceType
{
    foreach ($this->obligations as $obligation) {
        if (TrackedObligation::keyBelongsToRequirement($obligation->key(), $requirementId)) {
            return $obligation->type();
        }
    }

    return null;
}

/** Персональная позиция (origin=Personal) в акте требования: не из нормы, но трекается наравне. Возвращает ключ. */
public function addPersonalObligation(
    Uuid $id,
    string $requirementId,
    string $label,
    ComplianceType $type,
    Cadence $cadence,
    ?Quantity $quantity,
    string $departmentId,
): string {
    $key = TrackedObligation::keyOf($requirementId, $label);
    foreach ($this->obligations as $obligation) {
        if ($obligation->key() === $key) {
            throw new AppException(sprintf('Позиция «%s» уже есть в карточке.', trim($label)));
        }
    }
    $obligation = new TrackedObligation(
        $id, $this, $requirementId, $this->requirementNameFor($requirementId),
        trim($label), $type, $cadence, $quantity, $departmentId,
        TrackedObligation::ORIGIN_PERSONAL,
    );
    $this->putObligation($obligation);

    return $key;
}

private function requirementNameFor(string $requirementId): string
{
    foreach ($this->obligations as $obligation) {
        if (TrackedObligation::keyBelongsToRequirement($obligation->key(), $requirementId)) {
            return $obligation->requirementName();
        }
    }

    return '';
}
```
Проверить импорты: `ComplianceType`, `Cadence`, `Quantity` уже используются в файле; `Uuid` есть.

- [ ] **Шаг 1.4: `./run check unit`** — зелёный.

---

## Task 2: Application — ad-hoc строки в `SignDraft`

**Files:**
- Modify: `app/src/Compliance/Application/UseCase/Command/SignDraft/SignDraftCommand.php`
- Modify: `app/src/Compliance/Application/UseCase/Command/SignDraft/SignDraftCommandHandler.php`
- Test: `app/tests/Functional/Compliance/Application/UseCase/DraftFlowTest.php` (или новый `PersonalObligationFlowTest.php`)

**Interfaces:**
- Consumes: `ProfileCompliance::addPersonalObligation`, `typeOfRequirement` (Task 1); `RequirementDocument::requirementId()`; `IssuanceLineMapper` (без изменений — нормы), `ObligationDueCalculator`.
- Produces: `SignDraftCommand::$personalItems` — `list<array{label:string, amount?:string, unit?:string, manualDueDate:string}>`.

- [ ] **Шаг 2.1: `SignDraftCommand` — добавить `personalItems`**

```php
/** @phpstan-type PersonalInput array{label?: string, amount?: string, unit?: string, manualDueDate?: string} */
// в конструкторе:
    /** @param list<IssuanceInput> $items @param list<PersonalInput> $personalItems */
    public function __construct(
        public string $profileId,
        public string $documentId,
        public string $documentDate,
        public array $items,
        public ?string $stagedFileId = null,
        public array $personalItems = [],
    ) {
    }
```

- [ ] **Шаг 2.2: функц. тест — ad-hoc материальная позиция со сроком**

```php
public function test_personal_item_added_in_act_is_tracked_with_due_date(): void
{
    ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
    $draftId = $p... // открыть черновик выдачи требования (как в остальных тестах DraftFlow)
    $this->commandBus->execute(new SignDraftCommand(
        $p, $draftId, '2026-02-01',
        items: [/* норменные позиции как обычно */],
        stagedFileId: $this->stageComplianceScan(),
        personalItems: [['label' => 'Очки', 'amount' => '1', 'unit' => 'pcs', 'manualDueDate' => '2027-02-01']],
    ));
    $this->reload();
    $pc = $this->repo->findByProfile($p);
    $key = TrackedObligation::keyOf($r, 'Очки');
    $o = array_values(array_filter($pc->getObligations(), fn ($x) => $x->key() === $key))[0] ?? null;
    self::assertNotNull($o);
    self::assertSame('personal', $o->origin());
    self::assertEquals(new \DateTimeImmutable('2027-02-01'), $o->nextDueAt()); // срок = manualDueDate
    self::assertSame(1.0, $pc->heldOf($key));
}

public function test_personal_item_without_due_date_is_rejected(): void
{
    // personalItems с пустым manualDueDate → AppException «укажите срок»
}
```

- [ ] **Шаг 2.3: `SignDraftCommandHandler` — материализация перед assertIssuable**

Логика (вставить между получением `$profileCompliance` и `assertIssuable`): определить `requirementId` документа; тип акта через `typeOfRequirement`; на каждую `personalItems`-строку — валидация (label и `manualDueDate` обязательны; `amount` только для материального) → `addPersonalObligation(...)` с `cadence = new Cadence(CadenceKind::ByManufacturerDoc)` → собрать `IssuanceLine` (ключ из `addPersonalObligation`, дата = documentDate, quantity для материального, `manualDueDate` = срок) и добавить к `$lines`.

```php
$document = ... // найти RequirementDocument по $command->documentId (через getDocuments())
$requirementId = $document->requirementId();
$lines = $this->mapper->fromInput($command->items, $documentDate);

$type = $profileCompliance->typeOfRequirement($requirementId)
    ?? throw new AppException('Нельзя добавить позицию в карточку без нормы.');
foreach ($command->personalItems as $row) {
    $label = trim((string) ($row['label'] ?? ''));
    $due = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) ($row['manualDueDate'] ?? ''))) ?: null;
    if ('' === $label) { continue; }                                   // пустая строка — пропускаем
    if (null === $due) { throw new AppException(sprintf('Укажите срок окончания для позиции «%s».', $label)); }
    $quantity = ComplianceType::Material === $type ? $this->personalQuantity($row) : null;
    $key = $profileCompliance->addPersonalObligation(
        Uuid::v7(), $requirementId, $label, $type, new Cadence(CadenceKind::ByManufacturerDoc), $quantity,
        /* departmentId */ $profileCompliance->departmentOf($requirementId) // хелпер или из typeOf-обязанности
    );
    $lines[] = new IssuanceLine(Uuid::v7(), $key, $documentDate, $quantity, null, $due);
}

$profileCompliance->assertIssuable($lines);
// далее существующий код: промоут скана → signDraft → add
```
`personalQuantity($row)` — разбор amount+unit как в `IssuanceLineMapper::quantity` (вынести общий метод в маппер и звать `$this->mapper->quantityOf($row)`, чтобы не дублировать). `departmentId` для персональной — брать из любой обязанности требования (добавить в Task 1 простой геттер `departmentOf(requirementId): string` или вернуть departmentId из `typeOfRequirement`-прохода; решить в Task 1, чтобы не плодить обходы).

- [ ] **Шаг 2.4: `./run check unit functional`** — зелёный (тест-БД при дрейфе пересоздать).

---

## Task 3: Controller — проброс типа акта и `personalItems`

**Files:**
- Modify: `app/src/Compliance/Infrastructure/Controller/Fulfillment/IssueAction.php`

- [ ] **Шаг 3.1:** в POST-ветке прочитать `personalItems` и передать в `SignDraftCommand`:
```php
array_values((array) ($inputData['personalItems'] ?? [])),
```
(шестым аргументом; пятый — stagedFileId, уже есть.)

- [ ] **Шаг 3.2:** в issue-режиме (`renderForm`, ветка открытого черновика) добавить в `render(...)` ключ `requirementType` — тип акта для формы (материальный → показываем кол-во). Взять из `$row->type` первой обязанности требования, собранной в этом же методе (или `'material'` по умолчанию, если позиций нет).

- [ ] **Шаг 3.3:** тонкость контроллера соблюдена — вся логика материализации в хендлере (Task 2); контроллер только собирает DTO.

---

## Task 4: UI — «Добавить позицию» в акте выдачи

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/person/issue.html.twig`
- (возможно) reuse `app/assets/controllers/report_list_rows_controller.js`

- [ ] **Шаг 4.1:** в секции `#sec-items` оформления (ветка `{% else %}`, после цикла `rows`) добавить блок персональных строк на `data-controller="report-list-rows"`:
  - контейнер `rows` + `<template>` строки + кнопка «Добавить позицию» (`btn-soft-success`, как в отчётах);
  - поля строки по `requirementType`:
    - всегда: `personalItems[__i__][label]` (наименование), `personalItems[__i__][manualDueDate]` (срок окончания, обязателен — `required`-подсказка);
    - только `requirementType == 'material'`: `personalItems[__i__][amount]` + `personalItems[__i__][unit]` (select из `units`).
  - Перенумерация `report-list-rows` работает по первой числовой скобке (уже обобщена в Д6) → `personalItems[0][label]` корректно переиндексируется.
- [ ] **Шаг 4.2:** заголовок/подпись блока: «Персональные позиции (вне нормы)» + подсказка «Срок окончания обязателен». Стили — только существующие классы, новых не вводить.
- [ ] **Шаг 4.3:** `cd app && yarn dev` (меняли Twig). PHP-тесты фронт не трогают.

---

## Task 5: Гейт + ручной смоук

- [ ] **Шаг 5.1:** `./run check` — style/phpstan/unit/functional зелёные.
- [ ] **Шаг 5.2:** ручной смоук в браузере: открыть акт выдачи человека → «Добавить позицию» → очки, 1 шт, срок 2027 → приложить скан → оформить → на дашборде очки зелёные со сроком; дождаться воркера (списание/пересчёт по Д6 — async). Правка нормы требования → очки не исчезают.

---

## Что НЕ трогаем (работает как есть)

- **Списание** персональной позиции — тем же актом списания требования (requirementId совпадает); порции/held/дефицит-черновик по Д6 уже умеют.
- **Пересборка** (`rebuild()`), **событие оформления списания** (Д6-async) — personal переживает (origin-гард).
- **Дашборд/статус/доки** — по обязанностям/фактам требования; personal попадает автоматически.
- **Шаблоны .docx/.xlsx** — позиция проецируется как обычная строка требования.

## Выбор исполнения

Задача bounded (3 файла логики + UI), дизайн зафиксирован в плане. Рекомендую **нативно по шагам** (как вели ветку), финальное ревью — на выходе. SDD — избыточно.
