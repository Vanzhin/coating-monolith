# Compliance D-журнал — Журнальная выдача (снос актов, прямая дозапись) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Заменить акты-документы выдачи (`RequirementDocument` + черновик/топ-ап/подпись/экран «Акты») на прямую дозапись неизменяемых фактов в журнал (`recordIssuance`/`recordInstruction`), сохранив рабочим списание (`WriteOffAct`).

**Architecture:** Выдача материального и прохождение нематериального = append-only `FulfillmentRecord` прямо в агрегат (`documentId=null` → факт сразу «выдан»). Статус — по фактам (нет факта → Missing), гейт «подписанный документ» (`active`) отмирает. `WriteOffAct` (списание) остаётся; его завязки на выдачу-документы перепроводятся на факты. Порядок задач: сначала добавляем журнальный путь и перепроводим читателей, в самом конце сносим `RequirementDocument` + миграция — каждая задача оставляет `./run check` зелёным.

**Tech Stack:** PHP 8.5, Symfony, Doctrine ORM (XML), PHPUnit, Twig, Stimulus. DDD/Hexagonal.

**Spec:** `docs/plans/compliance-journal-model-design.md` (§3.1-3.3, §4.2, §5.1-5.2). Это деплой «журнальная выдача» (в дорожной карте шёл как D3; делаем ПЕРЕД выносом проекции — журнал развязывает командные пути от проекции).

## Global Constraints

- Доменные ошибки — `App\Shared\Infrastructure\Exception\AppException` (RU-сообщение). VO — `final readonly`. id передаётся в конструктор (feedback: id-passed-not-generated); генерация id — в Application.
- **Списание (`WriteOffAct`) обязано остаться рабочим на каждом шаге.** Его общий с выдачей код НЕ ломать: `FulfillmentRecord.heldAmount/addReturnedQuantity`, `heldOf`, `recordsForRequirement`, `recordById`, `recompute*`. 5 точек риска — см. Review Focus.
- **`DocumentStatus` enum НЕ удалять** — его разделяет `WriteOffAct`.
- Единый резолвер статуса (`ComplianceStatusResolver`) — не плодить второй ладдер. `TrackedObligation` (проекция) остаётся в домене (её вынос — следующий деплой); меняем только то, что кормит `active`/held.
- Миграции идемпотентные; имя версии позже последней существующей (на момент плана последняя — `Version20261020120500`).
- Прод НЕ пуст: `compliance_requirement_document` 26 (2 signed + 24 formed), `compliance_fulfillment_record` 126 (все с `document_id`). Миграция данных — в Task 8.
- Гейт — `./run check` зелёный (style/phpstan/unit/functional) на каждом шаге (feedback: sdd-run-check-gates). **ВАЖНО: `test_db` эфемерна — перед functional при пустой схеме прогнать `docker compose -f docker-compose.test.yml run --rm test_php-cli bin/console doctrine:migrations:migrate -n`.** Правка Twig/JS → `cd app && yarn dev` только если трогается JS/CSS (Twig — server-side, не требует).
- Сканы выдачи — ПАРКУЮТСЯ: поток скана карточки (staged-file, `RequirementScanPurpose::SignedCard`, DownloadScan) убираем, новый не строим.

## Review Focus (5 точек риска общего кода выдача↔списание)

- **`isIssued()`**: журнальная запись имеет `documentId=null` → должна считаться «выдано» (идёт в `heldOf`/held/даты). После сноса актов упростить, но проверить, что held/пересчёт не сломались. → Task 3, 7.
- **`startOrGetWriteOffDraft()` гард** `signedDocumentsFor(reqId) пуст → throw`: завязан на акты выдачи. Переписать на «есть выданные удерживаемые факты» (`recordsForRequirement` с `heldOf>0`), иначе списание перестанет открываться. → Task 2.
- **Каскад `deleteSignedDocument`** (при удалении акта → `WriteOffAct::removeItemsByRecordIds`, удаление опустевших актов списания + их сканы): перенести в новое «удалить запись журнала», не потерять каскад на списания. → Task 2, 6.
- **`FulfillmentRecord.documentId`**: не дропать колонку до миграции данных; журнал пишет `null`. → Task 8.
- **`active`→по фактам**: `ComplianceProjectionRebuilder` ставит `active = signedDocumentsFor(reqId) !== []`; без актов это всегда пусто → всё Missing. Заменить на «есть выданный факт по обязанности». → Task 3.

---

## File Structure

- Domain `app/src/Compliance/Domain/Aggregate/ProfileCompliance/`: `ProfileCompliance.php` (recordIssuance/recordInstruction/removeIssuanceRecord; снос draft-методов), `FulfillmentRecord.php` (instructionDetails в record-пути), `RequirementDocument.php` (**delete** в Task 7), `IssuanceLine.php` (оставить как DTO строки).
- Application Command: `RecordIssuance/` (**new**, замена `SaveDraft/`), удаление `SaveDraft/`,`FormDraft/`,`FormDraftsForRequirement/`,`DeleteDraft/`,`DeleteDocument/`→`DeleteIssuanceRecord/`.
- Application Service: `DraftFormationService.php` (**delete**), `RequirementCardProjector.php` (source→журнал).
- Application Query/DTO: `Acts/*` (**delete**), `Dashboard/RequirementGroupDTO.php`+`IssuanceActDTO.php`+`PersonRowDTOTransformer.php` (снять openDraft/signedActs).
- Application Event: `RecomputeOn*Handler` (убрать вызовы formation).
- Infrastructure Controller: `Fulfillment/IssueAction.php` (журнальная форма), удаление `Document/FormDraftAction`,`DeleteDraftAction`,`DownloadScanAction`,`Requirements/FormDraftsAction`,`Acts/ListActsAction`; `Document/DeleteDocumentAction`→запись журнала; `Document/DownloadCardAction` (by-requirement only).
- Infrastructure Repository: `ComplianceActRepository.php`(+interface/filter/sort) **delete**.
- Templates `admin/compliance/`: `person/issue.html.twig` (журнальная форма, снять act-режим), `dashboard/_person_detail.html.twig` (бейджи), `acts/*` **delete**.
- ORM: `ProfileCompliance.RequirementDocument.orm.xml` **delete**, `ProfileCompliance.ProfileCompliance.orm.xml` (снять documents one-to-many).
- Migration: `Version<ts>.php` — drop `compliance_requirement_document`, миграция `compliance_fulfillment_record.document_id`.
- Tests: см. Task-привязки (ProfileComplianceTest, DraftFlowTest[del], InstructionIssuanceTest, DeleteDocumentTest, WriteOffFlowTest, ActsListControllerTest[del], CardDownloadControllerTest, ComplianceDashboardQueryTest, EnrollsComplianceTrait).

---

### Task 1: Домен — `recordIssuance` / `recordInstruction` (прямая дозапись)

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php` (расширить `recordFulfillment` параметром `instructionDetails`; добавить `recordIssuance(ObligationDueCalculator, IssuanceLine ...$lines)` и `recordInstruction(...)`).
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/FulfillmentRecord.php` (конструктор уже принимает `instructionDetails` — убедиться, что `recordFulfillment` его прокидывает).
- Test: `app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php` (+ новые тест-методы).

**Interfaces:**
- Produces: `ProfileCompliance::recordIssuance(ObligationDueCalculator $calc, IssuanceLine ...$lines): void` — для каждой строки создаёт `FulfillmentRecord` (documentId=null), пересчитывает обязанность. Внутри зовёт `assertIssuable($lines)` (материальные: held+выдача ≥ норма). `recordInstruction(ObligationDueCalculator $calc, JournalKind $kind, IssuanceLine ...$lines): void` — то же + доменная проверка `$kind->assertDetailsComplete($line->instructionDetails())` по каждой строке.

- [ ] **Step 1: Write failing tests** — в `ProfileComplianceTest` добавь (используй существующий helper-стиль файла — enroll/obligation уже есть):

```php
public function test_record_issuance_appends_journal_lines_and_recomputes(): void
{
    $pc = $this->enrolledWithMaterialNorm('req-1', 'Перчатки', qty: 2.0); // существующий/аналогичный helper
    $pc->recordIssuance($this->calculator,
        new IssuanceLine(Uuid::v7(), $pc->keyForLabel('req-1','Перчатки'), new \DateTimeImmutable('2026-01-10'), new Quantity(2.0, Unit::Pair)));
    self::assertSame(2.0, $pc->heldOf($pc->keyForLabel('req-1','Перчатки')));
}

public function test_record_issuance_below_norm_throws(): void
{
    $pc = $this->enrolledWithMaterialNorm('req-1', 'Перчатки', qty: 2.0);
    $this->expectException(AppException::class);
    $pc->recordIssuance($this->calculator,
        new IssuanceLine(Uuid::v7(), $pc->keyForLabel('req-1','Перчатки'), new \DateTimeImmutable('2026-01-10'), new Quantity(1.0, Unit::Pair)));
}

public function test_record_instruction_requires_complete_fields(): void
{
    $pc = $this->enrolledWithNonMaterialNorm('req-2', 'Пожарный', JournalKind::FireSafety);
    $this->expectException(AppException::class);
    $pc->recordInstruction($this->calculator, JournalKind::FireSafety,
        new IssuanceLine(Uuid::v7(), $pc->keyForLabel('req-2','Пожарный'), new \DateTimeImmutable('2026-01-10'),
            instructionDetails: InstructionDetails::fromArray([]))); // пустые обязательные → throw
}
```
(Сверь имена helper-ов (`enrolledWithMaterialNorm`/`keyForLabel`) с фактическими в файле; если их нет — собери `ProfileCompliance` как в соседних тестах через `putObligation`+`recordFulfillment`-паттерн, уже присутствующий в файле.)

- [ ] **Step 2: Run, verify fail** — `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php` → FAIL (нет `recordIssuance`/`recordInstruction`).

- [ ] **Step 3: Implement** — в `ProfileCompliance`:

```php
public function recordIssuance(ObligationDueCalculator $calculator, IssuanceLine ...$lines): void
{
    $this->assertIssuable($lines);
    foreach ($lines as $line) {
        $this->records->add(new FulfillmentRecord(
            $line->recordId(), $this, $line->obligationKey(), $line->fulfilledAt(),
            $line->quantity(), $line->wearPercent()?->value(), $line->note(), $line->manualDueDate(),
            0.0, null /* documentId */, $line->instructionDetails(),
        ));
    }
    foreach ($this->distinctKeys($lines) as $key) {
        $this->recomputeObligation($key, $calculator);
    }
}

public function recordInstruction(ObligationDueCalculator $calculator, JournalKind $kind, IssuanceLine ...$lines): void
{
    foreach ($lines as $line) {
        $kind->assertDetailsComplete($line->instructionDetails());
    }
    $this->recordIssuance($calculator, ...$lines);
}
```
(Сверь точную сигнатуру конструктора `FulfillmentRecord` и `IssuanceLine`-геттеры с файлами; `wearPercent` в record — `?float`, в IssuanceLine — `?Percent`, бери `->value()`. Добавь private `distinctKeys(array $lines): array`. Если `recordFulfillment` ещё не прокидывает `instructionDetails` — не трогай его, новый `recordIssuance` пишет record напрямую.)

- [ ] **Step 4: Run tests pass** — тот же phpunit → PASS.
- [ ] **Step 5: Commit** — `Compliance: прямая дозапись в журнал — recordIssuance/recordInstruction (инвариант выдачи и полноты инструктажа в домене)`

---

### Task 2: Домен — развязать списание от актов выдачи

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php` (`startOrGetWriteOffDraft` гард; новый `removeIssuanceRecord`).
- Test: `ProfileComplianceTest.php`.

**Interfaces:**
- Consumes: `recordsForRequirement`, `heldOf`, `recordById`, write-off методы (не меняются).
- Produces: `startOrGetWriteOffDraft` гард теперь «есть удерживаемый выданный факт по требованию» (не `signedDocumentsFor`). `removeIssuanceRecord(Uuid $recordId, ObligationDueCalculator $calc): array` — удаляет факт журнала, каскадом снимает его порции из актов списания (перенос логики из `deleteSignedDocument`), возвращает `{requirementId, fileIds[]}`.

- [ ] **Step 1: failing tests** — (a) `test_start_write_off_requires_held_fact_not_document`: завести выданный факт прямой записью (без акта), `startOrGetWriteOffDraft` НЕ бросает; без фактов — бросает `AppException`. (b) `test_remove_issuance_record_cascades_to_write_off`: факт списан частью в подписанном акте → `removeIssuanceRecord` удаляет факт и его порции, опустевший акт списания удаляется.
- [ ] **Step 2: verify fail.**
- [ ] **Step 3: Implement** — перепиши гард:

```php
// было: if ([] === $this->signedDocumentsFor($requirementId)) throw ...
$heldFacts = array_filter(
    $this->recordsForRequirement($requirementId),
    fn (FulfillmentRecord $r): bool => $r->heldAmount() > 0.0,
);
if ([] === $heldFacts) {
    throw new AppException('Списать можно только то, что выдано и на руках.');
}
```
И вынеси каскад из `deleteSignedDocument` в:
```php
public function removeIssuanceRecord(Uuid $recordId, ObligationDueCalculator $calculator): array
{
    $record = $this->recordById($recordId);
    if (null === $record) { throw new AppException('Запись журнала не найдена.'); }
    $key = $record->obligationKey();
    $requirementId = $this->requirementIdOfKey($key);
    $fileIds = $this->detachRecordFromWriteOffActs($recordId); // перенос из deleteSignedDocument: removeItemsByRecordIds + удаление пустых актов + сбор scanFileId
    $this->records->removeElement($record);
    $this->recomputeObligation($key, $calculator);
    $this->pruneOrphanPersonalObligations();
    return ['requirementId' => $requirementId, 'fileIds' => $fileIds];
}
```
(Приватные `detachRecordFromWriteOffActs` и `requirementIdOfKey` собери из существующего тела `deleteSignedDocument`/`TrackedObligation::keyBelongsToRequirement`. `deleteSignedDocument` пока оставь — удалим в Task 7.)

- [ ] **Step 4: tests pass.**
- [ ] **Step 5: Commit** — `Compliance: списание опирается на выданные факты, а не на акт выдачи; удаление записи журнала каскадит на акты списания`

---

### Task 3: `active` и статус — по фактам, без актов выдачи

**Files:**
- Modify: `app/src/Compliance/Application/Service/ComplianceProjectionRebuilder.php` (active = есть выданный факт обязанности, не `signedDocumentsFor`).
- Modify (если нужно): `ProfileCompliance.php` — хелпер `hasIssuedFactFor(string $key): bool`.
- Test: `app/tests/Functional/Compliance/Application/UseCase/ComplianceDashboardQueryTest.php` (адаптировать: прямой факт → активно/в норме, без подписи акта).

**Interfaces:**
- Produces: `ProfileCompliance::hasIssuedFactFor(string $obligationKey): bool` (есть issued-факт по ключу). Rebuilder ставит `active` из него.

- [ ] **Step 1: failing test** — перепиши в `ComplianceDashboardQueryTest` setup «подпись акта» на прямую запись факта; ожидание: записанный факт → Ok/активно; нет факта → Missing. (Старые `_unsigned_person_is_missing`/`_signed_and_fulfilled_becomes_ok` перепиши под факты.)
- [ ] **Step 2: verify fail** (functional; при пустой test_db сперва migrate — см. Global Constraints).
- [ ] **Step 3: Implement** — в rebuilder заменить:
```php
// было: $active = [] !== $profileCompliance->signedDocumentsFor($requirement->getId());
// станет: активность на обязанность — по факту
```
Проще: убрать пер-требование `$active`, а `putObligation` + после сборки для каждой obligation `setActive($profileCompliance->hasIssuedFactFor($key))`. Добавь в агрегат:
```php
public function hasIssuedFactFor(string $obligationKey): bool
{
    foreach ($this->records as $record) {
        if ($record->obligationKey() === $obligationKey && $this->isIssued($record)) { return true; }
    }
    return false;
}
```
- [ ] **Step 4: tests pass** (`./run check` functional зелёный).
- [ ] **Step 5: Commit** — `Compliance: статус обязанности активен по факту выдачи, а не по подписанному акту`

---

### Task 4: Application + форма — журнальная выдача вместо черновика/подписи

**Files:**
- Create: `app/src/Compliance/Application/UseCase/Command/RecordIssuance/RecordIssuanceCommand.php` + `RecordIssuanceCommandHandler.php` (заменяет `SaveDraft`).
- Modify: `app/src/Compliance/Infrastructure/Controller/Fulfillment/IssueAction.php` (issue-режим → журнальная форма; снять act-режим `renderAct`; writeoff-режим оставить).
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/person/issue.html.twig` (issue-блок: убрать draftId/save-черновик/скан; одна кнопка «Записать выдачу»; дефицит-предзаполнение; personal items; norm-fill оставить).
- Keep: `Fulfillment/NextDueAction.php`, `norm_fill_controller.js` (без изменений).
- Test: `app/tests/Functional/Compliance/Application/UseCase/InstructionIssuanceTest.php` (→ recordInstruction), новый `RecordIssuanceTest`.

**Interfaces:**
- Consumes: `recordIssuance`/`recordInstruction` (Task 1), `IssuanceLineMapper`.
- Produces: `RecordIssuanceCommand {profileId, requirementId, documentDate, items[], personalItems[]}` (без actNumber/responsibleFio/stagedFileId/sign). Handler: `canManage` → `findByProfile` → mapper lines → `materializePersonalItems` → по типу требования `recordInstruction`(journalKind) или `recordIssuance` → `repository->add`.

- [ ] **Step 1: failing test** — `RecordIssuanceTest::test_records_material_issuance` и `InstructionIssuanceTest` перепиши: один вызов `RecordIssuanceCommand` пишет факты (held растёт; инструктаж с неполными полями → AppException). Больше нет form/save/sign.
- [ ] **Step 2: verify fail.**
- [ ] **Step 3: Implement** — Handler (по образцу `SaveDraftCommandHandler`, но без документа/скана/sign-ветки):
```php
public function __invoke(RecordIssuanceCommand $command): void
{
    if (!$this->access->canManage()) { throw new ForbiddenException(); }
    $pc = $this->repository->findByProfile($command->profileId) ?? throw new AppException('Учёт по сотруднику не создан.');
    $date = /* parse documentDate */;
    $lines = $this->mapper->fromInput($command->items, $date);
    $lines = [...$lines, ...$this->materializePersonalItems($pc, $command->personalItems, $date)];
    $requirement = $this->requirements->findOneById($command->requirementId);
    if (null !== $requirement?->getJournalKind()) {
        $pc->recordInstruction($this->calculator, $requirement->getJournalKind(), ...$lines);
    } else {
        $pc->recordIssuance($this->calculator, ...$lines);
    }
    $this->repository->add($pc);
}
```
`IssueAction`: POST → `RecordIssuanceCommand`; успех → flash + redirect на карточку человека; `AppException` → re-render формы с `$inputData` (паттерн Покрытий). Удали ветку `renderAct`/`documentId`-роут. `renderForm` issue-режим: `issueRows` из текущей нормы (obligations), `deficit = max(0, норма − heldOf(key))`, `instructionGroups` из `journalKind->fields()`, personal-блок. Writeoff-режим не трогай. Шаблон: issue-блок — `<form data-controller="norm-fill">` без hidden draftId/скана, кнопка «Записать выдачу» (одна, btn-primary), «Скачать бланк» оставить.
- [ ] **Step 4: tests pass + `cd app && yarn dev`** (если менялся JS — нет; Twig только) → проверка `./run check`.
- [ ] **Step 5: Commit** — `Compliance: выдача пишется прямо в журнал одной формой — без черновика, топ-апа и подписи-над-выдачей`

---

### Task 5: Проектор карточки + дашборд-read — без актов/черновика

**Files:**
- Modify: `app/src/Compliance/Application/Service/RequirementCardProjector.php` (лог = весь журнал фактов требования, без `$source`-документа; реквизиты №/ответственный — убрать или из последней записи).
- Modify: `app/src/Compliance/Application/DTO/Dashboard/RequirementGroupDTO.php` (убрать `openDraftId`,`signedActs`), `IssuanceActDTO.php` (**delete**), `PersonRowDTOTransformer.php` (`group()` — снять openDraftFor/signedActs).
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/dashboard/_person_detail.html.twig` (бейджи «Черновик/Подписан/Нет карточки» → «Есть записи / Нет записей» по журналу).
- Test: `app/tests/Functional/Compliance/Infrastructure/Controller/CardDownloadControllerTest.php` (печать журнала; убрать by-document/draft-details кейсы), dashboard overview тест.

- [ ] **Step 1: failing tests** — card download печатает лог ВСЕХ фактов требования (не одного акта); dashboard group без openDraftId/signedActs.
- [ ] **Step 2: verify fail.**
- [ ] **Step 3: Implement** — `RequirementCardProjector::factLog`: обходить `recordsForRequirement($requirementId)` целиком (без фильтра `documentId === source`); возвраты из подписанных `writeOffActs` (как было). Убрать `$source`/`latestSigned`/`documentOf`, №/ответственный — из последнего факта или пусто. `PersonRowDTOTransformer::group()` — убрать строки `openDraftId`/`signedActs`. Удалить `IssuanceActDTO`. `_person_detail`: ветки бейджа по `g.rows` (есть/нет записей).
- [ ] **Step 4: tests pass.**
- [ ] **Step 5: Commit** — `Compliance: карточка печатает весь журнал фактов, дашборд без черновиков/актов выдачи`

---

### Task 6: Снести экран «Акты» + устаревшие экшены/команды/роуты

**ВАЖНО (порядок внутри задачи):** `EnrollsComplianceTrait::issueCard` и ряд функц.тестов используют `FormDraftCommand`/`SaveDraftCommand`/`formDraft`/`signDraft`. СНАЧАЛА переписать их на прямой `recordIssuance`, ПОТОМ удалять команды/экшены — иначе гейт красный. (Рерайт хелпера перенесён сюда из T8.)

**Files (delete):** `Infrastructure/Controller/Acts/ListActsAction.php`, `Application/UseCase/Query/Acts/*`, `Infrastructure/Repository/ComplianceActRepository.php` (+`Domain/Repository/ComplianceActRepositoryInterface.php`, `ComplianceActsFilter`, `ComplianceActsSort`), `Application/DTO/Acts/ComplianceActRowDTO.php`, `Application/Service/ComplianceActViewBuilder.php` (мёртв с T4), `Templates/admin/compliance/acts/*`; `Controller/Document/FormDraftAction.php`, `Controller/Requirements/FormDraftsAction.php`, `Controller/Document/DeleteDraftAction.php`, `Controller/Document/DownloadScanAction.php`, `Controller/Document/DeleteDocumentAction.php`; команды `FormDraft/`, `FormDraftsForRequirement/`, `DeleteDraft/`, `DeleteDocument/`, `SaveDraft/`.
**Modify:** `Controller/Document/DownloadCardAction.php` — убрать `{documentId}`-вариант роута (content-нейтрален с T5), оставить by-requirement. `app/tests/Support/EnrollsComplianceTrait.php` — `issueCard()`→прямой `recordIssuance`; обновить потребителей.
**Test (delete):** `ActsListControllerTest.php`, `DraftFlowTest.php`, `DeleteDocumentTest.php` (удаление акта-сущности уходит; каскад на списания уже покрыт unit-тестом `removeIssuanceRecord` из T2).

Record-deletion UI — follow-up (домен `removeIssuanceRecord` остаётся заделом; экран-источник «Удалить акт» ушёл вместе с «Актами»).

- [ ] **Step 1 (СНАЧАЛА):** переписать `EnrollsComplianceTrait::issueCard` → прямой `recordIssuance` (без FormDraft/SaveDraft/signDraft); прогнать и починить всех потребителей (`WriteOffFlowTest` setup, `CardDownloadControllerTest`, др.) — зелёные БЕЗ draft/act-команд.
- [ ] **Step 2:** grep удаляемых роутов/классов (`app_cabinet_compliance_acts`, `_form_draft`, `_draft_delete`, `_document_download_scan`, `_document_delete`, `SaveDraftCommand`, `ComplianceActViewBuilder`) — ноль живых ссылок после T4/T5 и Step 1. Список в отчёт.
- [ ] **Step 3:** удалить файлы+их тесты; убрать `{documentId}` из DownloadCardAction-роута; убрать пункт «Акты» из сайдбара, если есть.
- [ ] **Step 4:** `./run check` зелёный; `bin/console debug:router` без осиротевших. Перед functional — migrate test_db при пустой схеме.
- [ ] **Step 5: Commit** — `Compliance: убран экран «Акты», черновиковые экшены и команды выдачи — учёт через журнал`

КЕЕП до T7: `RequirementDocument` + доменные draft-методы + `DraftFormationService` (станут неиспользуемы после T6, снос — T7).

---

### Task 7: Снести `RequirementDocument` + машинерию черновика из домена

**Files:**
- Delete: `Domain/Aggregate/ProfileCompliance/RequirementDocument.php`, `Infrastructure/Database/ORM/Aggregate/ProfileCompliance.RequirementDocument.orm.xml`, `Application/Service/DraftFormationService.php`.
- Modify: `ProfileCompliance.php` — удалить `documents` collection + методы `formDraft/saveDraft/signDraft/recordAndSign/topUpDraftFromNorm/openDraftFor/signedDocumentsFor/documentById/deleteDraft/deleteSignedDocument` + приватные cart-хелперы; упростить `isIssued` (нет documents → `return true`); проверить `recordsForRequirement/heldOf/recompute*` целы.
- Modify: `ProfileCompliance.ProfileCompliance.orm.xml` — снять `one-to-many documents`.
- Modify: события `RecomputeOnRequirementChangedHandler`/`ProfileSaved`/`WriteOffActSigned`/`DraftDeleted` — убрать вызовы `DraftFormationService`; оставить только `rebuilder->rebuild(...)`. `DraftDeleted`-событие и его хендлер — удалить, если больше не кидается (проверить издателей).
- Modify: `ProfileComplianceTest.php` — удалить все draft/act-тесты (список в карте §12), оставить журнал/списание/прямую запись.

- [ ] **Step 1** — grep `RequirementDocument`, `signedDocumentsFor`, `openDraftFor`, `DraftFormationService`, `formDraft`, `saveDraft`, `signDraft`, `topUpDraftFromNorm` по `app/src` — подтвердить ноль ссылок вне удаляемого (после Task 4-6). Список в отчёт; если есть живые — остановиться/доработать.
- [ ] **Step 2** — удалить/упростить; `isIssued` → `return true` (с комментарием: прямой журнал, документов нет). Проверить `WriteOffAct`-путь цел (`startOrGetWriteOffDraft` уже на фактах — Task 2).
- [ ] **Step 3** — `./run check` зелёный (unit домена + functional списания зелёные). **Перед functional — migrate test_db (эфемерна).**
- [ ] **Step 4: Commit** — `Compliance: из домена удалены акты выдачи и формирование черновиков — остаётся журнал фактов + списание`

---

### Task 8: Миграция БД + чистка тест-инфраструктуры + полный гейт

**Files:**
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version<ts>.php` (> `Version20261020120500`).
- (Рерайт `EnrollsComplianceTrait`/тестов перенесён в T6 — см. ordering-note там. T8 = только миграция + остаточная чистка, если осталась.)

- [ ] **Step 1: Migration** — идемпотентно:
```php
public function up(Schema $schema): void
{
    // факты 2 подписанных актов → строки журнала (отвязать document_id);
    // факты формед-черновиков (брошенные корзины) → отбросить.
    $this->addSql("DELETE FROM compliance_fulfillment_record WHERE document_id IN (SELECT id FROM compliance_requirement_document WHERE status = 'formed')");
    $this->addSql('UPDATE compliance_fulfillment_record SET document_id = NULL WHERE document_id IS NOT NULL');
    $this->addSql('DROP TABLE IF EXISTS compliance_requirement_document');
}
public function down(Schema $schema): void { /* необратимо: таблица актов снесена */ }
```
(`document_id` колонку оставить — journal пишет NULL; дроп колонки — отдельно позже, если понадобится.)
- [ ] **Step 2: Apply** — dev: `cd app && bin/console doctrine:migrations:migrate -n`; test_db: `docker compose -f docker-compose.test.yml run --rm test_php-cli bin/console doctrine:migrations:migrate -n`.
- [ ] **Step 3: Test helper** — `EnrollsComplianceTrait::enrollAndIssue()` (замена `issueCard`): `recordIssuance` напрямую; обнови всех потребителей (`WriteOffFlowTest`, др.).
- [ ] **Step 4: Full gate** — `./run check` зелёный целиком; `bin/console app:compliance:rebuild-projection` на dev; браузер: карточка человека — форма «Записать выдачу», записал → факт в журнале, held/светофор ок; списание (writeoff) открывается и проводится.
- [ ] **Step 5: Commit** — `Compliance: миграция актов выдачи в журнал (подписанные → факты, брошенные черновики отброшены), чистка тестов`

---

### Task 9: §5.1 — «Выдать» и «Списать» доступны ВСЕГДА (развилка решена заказчиком 2026-10-15)

Решение заказчика: выдача и списание доступны **всегда**, не either/or по статусу нормы. Форма выдачи открывается всегда и показывает ВСЕ позиции текущей нормы (дефицит-предзаполнение, но количество переопределяемо — замена/досрочная выдача даже при полностью Green-норме); списание доступно всегда (инвентарные с held>0). Снимает парк-рулинг §5.1 из T4.

**Files:**
- Modify: `app/src/Compliance/Infrastructure/Controller/Fulfillment/IssueAction.php` — убрать гейт «issue-режим только когда есть позиции ≠Green» (`:105` фильтр, `:128` выбор режима). issue-форма строится ВСЕГДА из всех позиций текущей нормы, дефицит-предзаполнение, кол-во переопределяемо. «Выдать»(issue) и «Списать»(writeoff) — ДВА всегда-доступных входа, не взаимоисключающие по статусу.
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/person/issue.html.twig` + точка входа на карточке человека (`dashboard/_person_detail.html.twig` / превью / дашборд) — дать ОБА действия «Выдать»/«Списать» всегда.
- Test: functional — при Green-норме issue-форма открывается и записывает замену (held растёт сверх нормы); writeoff доступен параллельно.

- [ ] **Step 1: failing test** — Green-норма → issue-форма открывается, запись замены пишет факт (held > норма); writeoff доступен.
- [ ] **Step 2: verify fail.**
- [ ] **Step 3: Implement** — IssueAction: issue-форма всегда (все позиции нормы, дефицит-предзаполнение, кол-во overridable); разъединить «Выдать»/«Списать» как раздельные всегда-доступные входы; шаблон/карточка — обе кнопки всегда.
- [ ] **Step 4:** `./run check` зелёный (test_db migrate при пустой схеме). Браузер: при полной норме записывается замена; списание доступно.
- [ ] **Step 5: Commit** — `Compliance: выдать и списать доступны всегда — форма выдачи не гейтится статусом нормы (замена/досрочная)`

---

## Self-Review (выполнено при написании)

- **Spec coverage:** §3.1-3.3 (журнал, прямая дозапись, нематериальное тоже журнал) → T1,T4. §4.2 (recordIssuance/recordInstruction, invariants в домене) → T1. §5.1-5.2 (форма выдачи/отметки) → T4. Снос актов/черновика/топ-апа → T6,T7. Миграция §8 → T8.
- **Порядок зелёного:** добавляем журнальный путь (T1-3) → переводим читателей/форму (T4-5) → сносим экран и экшены (T6) → сносим домен-акты (T7, когда ссылок нет) → миграция (T8). Ни один шаг не оставляет битых ссылок.
- **5 точек риска (Review Focus)** закрыты: isIssued (T3/T7), writeoff-гард (T2), каскад удаления (T2/T6), documentId не дропаем (T8), active→факты (T3).
- **Placeholders:** код новых доменных методов и ключевых изменений приведён; удаления заданы точными списками файлов/методов; тестам задан паттерн setup (`recordIssuance`) + список файлов (verbatim 400-стр. тесты не копируются — имплементер правит по месту, паттерны есть в файлах).
- **Списание:** `WriteOffAct` и общий код сохранены рабочими на каждом шаге (T2 развязывает гард заранее).
