# Деплой 3: учёт по человеку (`PersonCompliance`), журнал фактов, трекинг сроков, Excel-карточка СИЗ

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development или superpowers:executing-plans. Шаги — чекбоксы.
>
> Соседи: `-1-personnel.md` (нужен: `Profile`, `Position`), `-2-requirements.md` (нужен: `PositionRequirements`, `ComplianceType`, `Cadence`, `Quantity`), `-4-dashboard-alerts.md`. Спека: `docs/plans/compliance-siz-design.md`. Самодостаточен.

**Цель:** агрегат `PersonCompliance` (один на человека) — отслеживаемые обязанности (`TrackedObligation`), унаследованные снапшотом из требований должности + индивидуальные отклонения; журнал фактов (`FulfillmentRecord`: дата+файл, для СИЗ — кол-во/%износа); доменный трекинг `nextDueAt`+`ComplianceStatus`; генерация Excel «Личная карточка учёта выдачи СИЗ».

**Архитектура:** `PersonCompliance` — корень; `TrackedObligation`/`FulfillmentRecord` — **реляционные дочерние сущности** (не jsonb) с индексируемыми `next_due_at`/`status`/`type`/`subject_user_ulid` (для дашборда/планировщика в Д4). Пересчёт `nextDueAt`+`status` — в домене при добавлении/правке факта. Снапшот требований должности при создании (заморозка, как `Report::seedSystemLayers`). Документ — через shared `TemplateRendering` (PhpSpreadsheet), проектор+шаблон+readiness (паттерн Reports). Файл — shared File-backbone (stage→promote, `FilePurpose`).

**Tech Stack:** как Д1/Д2 + `App\Shared\Infrastructure\Service\TemplateRendering`, `App\Shared\Domain\File\{FileStorage,FilePurpose,FileConstraints}`.

## Global Constraints

- (Все ограничения Д1/Д2: VO+`AppException` RU, правила в домене, marker-interface хендлеры, id из вне, тонкие per-action контроллеры, `ComplianceAccessControl` доступ, тесты зеркалят `src/`+функц. с реальной БД+`authenticateAsSystem()`, фронт сборка+браузер, стили копируем, `./run check` в контейнере, миграции идемпотентные, коммиты по задаче/пуш по апруву.)
- Учёт **всегда редактируем админом** — доменного edit-lock/статуса жизненного цикла НЕТ (только гейт роли).
- Данные трекинга — **реляционно** (запрашиваемо), НЕ документ-снапшот в одном jsonb. `next_due_at`/`status`/`type` — колонки+индексы.
- Файл-скан: `ownerId = PersonCompliance.id`, храним UUID `StoredFile` строкой; обязателен `FileAccessControl` (иначе `/cabinet/file/{uuid}` → 403 deny-by-default).

## Review Focus

- **Факт без предыдущих** (первая выдача) → `lastFulfilledAt` ставится, `nextDueAt` пересчитан из cadence, `status` уходит из Red в Green/Yellow (тест в T3).
- **Правка/удаление последнего факта** → `lastFulfilledAt`/`nextDueAt`/`status` пересчитываются по фактически оставшимся записям, а не «залипают» (тест в T3).
- **Однократная обязанность после выполнения** → `status=Green`, `nextDueAt=null`, повторный алерт не грозит (тест в T3).
- **Генерация документа при неполных данных** (нет выданных позиций / профиль без размеров, если шаблон их требует) → readiness сообщает «чего не хватает», не падает 500 (тест в T6).
- **Осиротевший скан** — при удалении факта/учёта промоутнутый файл удаляется (`removeDetachedFiles`), не копится (тест в T5).
- **Ресинк требований**: изменили `PositionRequirements` — существующий `PersonCompliance` не переписан молча; обновление — явным действием с сохранением журнала (тест в T4).

---

## T1. `ComplianceStatus` (enum) + пороги

**Файл (новый):** `app/src/Compliance/Domain/Type/ComplianceStatus.php` — `enum ComplianceStatus: string { Green='green'; Yellow='yellow'; Red='red'; }`. `title()`, `worseOf(self $a, self $b): self` (Red>Yellow>Green), `severity(): int`. Константа порога жёлтого — `App\Compliance\Domain\Service\ComplianceClock` или прямо в статус-калькуляторе: `DUE_SOON_DAYS = 14`.

- [ ] Юнит: `worseOf` таблица (Red доминирует), severity-порядок.
- [ ] Коммит.

## T2. Доменный сервис `ObligationStatusCalculator`

**Файл (новый):** `app/src/Compliance/Domain/Service/ObligationStatusCalculator.php` — `final class`. Метод `compute(Cadence $cadence, ?\DateTimeImmutable $lastFulfilledAt, ?\DateTimeImmutable $manualNextDue, \DateTimeImmutable $now): array{status: ComplianceStatus, nextDueAt: ?\DateTimeImmutable}`:
- `lastFulfilledAt === null` → `{Red, null}` (требуется, ни разу не выполнено).
- иначе периодическая (`cadence->isPeriodic()`): `nextDueAt = cadence->nextDueFrom(lastFulfilledAt)`; если `nextDueAt < now` → Red; `nextDueAt <= now + 14д` → Yellow; иначе Green.
- `Once` выполнена → `{Green, null}`.
- `ByManufacturerDoc`: `nextDueAt = manualNextDue` (введён на факте); если null → Green (выполнено, срок не задан) — **уточнить с пользователем**: считать ли «по докам без срока» вечно-зелёным (принято: да, пока не задан ручной срок).

Now-провайдер — через инъекцию (`ClockInterface`/аналог проекта, чтобы тестировать; если в проекте есть — использовать, иначе передавать `\DateTimeImmutable $now` параметром).

- [ ] Юнит `ObligationStatusCalculatorTest`: не выполнено→Red; периодическая просрочена→Red; до срока 10 дней→Yellow; 60 дней→Green; Once выполнена→Green/null; граница ровно 14 дней→Yellow.
- [ ] Коммит.

## T3. Агрегат `PersonCompliance` + `TrackedObligation` + `FulfillmentRecord`

**Файлы (новые):**
- `app/src/Compliance/Domain/Aggregate/PersonCompliance/PersonCompliance.php` — `class PersonCompliance extends Aggregate`. Поля: `Uuid $id`, `string $subjectUserUlid`, `Collection $obligations` (TrackedObligation, one-to-many, cascade), `Collection $records` (FulfillmentRecord, one-to-many, cascade), `createdAt/updatedAt`, `int $version`. Один на `subjectUserUlid` (спека уникальности). Методы:
  - `seedFrom(RequirementLine ...$lines)` — создаёт `TrackedObligation` по каждой строке (снапшот label/type/cadence/quantity), статус пересчитан (пусто→Red).
  - `addObligation(...)`/`removeObligation(id)` — индивидуальные отклонения.
  - `recordFulfillment(string $obligationId, \DateTimeImmutable $fulfilledAt, ?Quantity $qty, ?Percent $wear, ?string $note, ?string $fileId, ?\DateTimeImmutable $manualNextDue)` — добавляет `FulfillmentRecord`, привязывает к obligation, вызывает `obligation->recalculate($calculator, $now)`.
  - `editRecord`/`removeRecord` — правит журнал, затем `obligation->recalculate` по оставшимся фактам (берёт максимальный `fulfilledAt`).
  - `resyncFrom(RequirementLine ...$lines)` — обновляет набор obligations из новых требований, **сохраняя журнал** для совпавших (match по label+type); новые добавляет, исчезнувшие помечает/удаляет (решение: удалять только без фактов, иначе оставлять с пометкой «вне нормы»).
  - `worstStatus(?ComplianceType $filter): ComplianceStatus` — агрегат по obligations (с фильтром типа).
- `app/src/Compliance/Domain/Aggregate/PersonCompliance/TrackedObligation.php` — сущность: `Uuid $id`, ссылка на root, `string $label`, `ComplianceType $type`, `Cadence $cadence`, `?Quantity $quantity`, `?\DateTimeImmutable $lastFulfilledAt`, `?\DateTimeImmutable $nextDueAt`, `ComplianceStatus $status`, `bool $inNorm=true`. `recalculate(ObligationStatusCalculator $calc, \DateTimeImmutable $now)` — берёт последний факт, пересчитывает `lastFulfilledAt/nextDueAt/status`.
- `app/src/Compliance/Domain/Aggregate/PersonCompliance/FulfillmentRecord.php` — сущность: `Uuid $id`, ссылка на root + `obligationId`, `\DateTimeImmutable $fulfilledAt`, `?Quantity $quantity`, `?Percent $wearPercent`, `?string $note`, `?string $fileId`, `?\DateTimeImmutable $returnedAt`, `?Quantity $returnedQuantity`.
- `Percent` — переиспользовать shared `App\Shared\Domain\...\Percent` (используется в Reports); проверить путь, иначе завести VO [0;100].
- Репозиторий `PersonComplianceRepositoryInterface` (`add`, `findOneById`, `findBySubject(string): ?PersonCompliance`, `findObligationsDueBefore(\DateTimeImmutable): array` — для Д4) + реализация.
- ORM: `PersonCompliance.PersonCompliance.orm.xml` (root, one-to-many к obligation/record, fetch-join при загрузке — учесть readonly-id+to-one, addSelect), `PersonCompliance.TrackedObligation.orm.xml` (таблица `compliance_tracked_obligation`, колонки+индексы `subject_user_ulid`? — денормализовать subject на obligation для дашборд-запросов; `next_due_at`, `status`, `type` индексируются), `PersonCompliance.FulfillmentRecord.orm.xml` (таблица `compliance_fulfillment_record`). VO `Cadence`/`Quantity`/`Percent` — jsonb-типы (из Д2 + shared).

> Денормализация: на `TrackedObligation` дублируем `subject_user_ulid` (и, для Д4-группировки, `department_id` из профиля-снапшота при seed) — чтобы дашборд/планировщик фильтровали без join к root/профилю.

- [ ] Миграции: таблицы `compliance_person_compliance`, `compliance_tracked_obligation` (индексы `subject_user_ulid`, `next_due_at`, `status`, `type`), `compliance_fulfillment_record` (индекс `obligation_id`). Идемпотентно.
- [ ] Функц. тест агрегата: seedFrom→все Red; recordFulfillment→статус пересчитан, nextDueAt из cadence; удаление последнего факта→откат в Red; Once после факта→Green/null; worstStatus с фильтром.
- [ ] Коммит.

## T4. Создание учёта + отклонения + ресинк (команды/хендлеры)

**Файлы (новые):**
- `app/src/Compliance/Domain/Factory/PersonComplianceMaker.php` — создаёт `PersonCompliance(Uuid, subjectUserUlid)`, тянет должность человека (`GetProfileByUserUlid` из `Personnel` через query-шину → positionId+departmentId), грузит `PositionRequirements.findByPositionId` → `seedFrom(...lines)`; проставляет `department_id` на obligations (для Д4).
- Command `CreatePersonComplianceCommand(subjectUserUlid)` + Handler (`canManage`); `AddIndividualObligationCommand`, `RemoveObligationCommand`, `ResyncRequirementsCommand(subjectUserUlid)` (перечитать требования должности, `resyncFrom`, сохранить журнал).
- Query `GetPersonCompliance(subjectUserUlid): PersonComplianceDTO` (obligations+records как вложенные DTO).

- [ ] Функц. тесты: create тянет снапшот требований; resync после изменения требований — журнал совпавших сохранён, новая строка добавлена; отклонения add/remove.
- [ ] Коммит.

## T5. Журнал фактов + скан (File-backbone) + контроллеры

**Файлы (новые):**
- `app/src/Compliance/Domain/File/PpeCardPurpose.php` — `enum PpeCardPurpose: string implements App\Shared\Domain\File\FilePurpose { case SignedCard='signed_card'; }`. `storagePrefix()`→`'compliance/ppe/signed_card'`, `key()`→`'compliance.ppe.signed_card'`, `constraints()`→`new FileConstraints(10*1024*1024, ['application/pdf','image/jpeg','image/png'])`.
- `app/src/Compliance/Application/Service/AccessControl/PpeCardFileAccessControl.php` — `implements App\Shared\Application\File\FileAccessControl` (тег `app.file_access_control`): `supports(?string $key)` = `$key===PpeCardPurpose::SignedCard->key()`; `canView(StoredFile $f)` — грузит `PersonCompliance` по `f->ownerId()`, делегирует `ComplianceAccessControl` (просмотр — авторизованным; правка — админ).
- Command `RecordFulfillmentCommand(subjectUserUlid, obligationId, fulfilledAt, quantity?, wearPercent?, note?, stagedFileId?, manualNextDue?)` + Handler: `canManage`; если `stagedFileId` — `storage->promote(stagedFileId, PpeCardPurpose::SignedCard, personCompliance.id)`; `person->recordFulfillment(...)`; удалить откреплённые файлы. `EditFulfillmentCommand`, `RemoveFulfillmentCommand` (+ `removeDetachedFiles`).
- Контроллеры `Infrastructure/Controller/Fulfillment/{RecordAction,EditAction,DeleteAction}.php` — тонкие; скан заливается через существующий `/cabinet/file/stage` (только uuid доходит до хендлера).

- [ ] Функц. тесты: record с файлом (stage→promote, ownerId=card id); remove факта удаляет откреплённый файл; wear/quantity сохранены.
- [ ] `yarn dev` + браузер: зафиксировать выдачу СИЗ со сканом; удалить — файл исчез.
- [ ] Коммит.

## T6. Excel-документ «Личная карточка учёта выдачи СИЗ»

**Файлы (новые):**
- Шаблон `app/src/Compliance/Infrastructure/Resources/templates/ppe_card.xlsx` — по образцу пользователя (лицевая: идентичность + таблица СИЗ-норм; оборотная: журнал). Плейсхолдеры `{{key}}`; повторяющиеся строки СИЗ — cloneRow-паттерн движка (`RepeatValue`). Формат — Excel (PhpSpreadsheet-драйвер выбирается по расширению).
- `app/src/Compliance/Application/Service/PpeCardRenderDataProjector.php` — `project(PersonCompliance, Profile): RenderData`: идентичность из `Profile` (ФИО, должность, организация, отдел, таб.№, размеры), СИЗ-обязанности (`type=Ppe`) как `RepeatValue` строк {наименование, основание, периодичность(label), кол-во}, журнал как `RepeatValue` {дата, кол-во, %износа, возврат}. Presence-driven `put` (пустые не эмитим).
- `app/src/Compliance/Infrastructure/Service/PpeCardReadinessChecker.php` — `missing(...): array` через `TemplateRendering::validate()` + humanize (паттерн `ReportReadinessChecker`).
- Контроллер `Infrastructure/Controller/Document/GeneratePpeCardAction.php` — `GetPpeCardRenderDataQuery` → readiness-гейт (flash+redirect если неполно) → `TemplateRendering::render()` → стрим `.xlsx` («Карточка-{ФИО}»).
- Query `GetPpeCardRenderData(subjectUserUlid): {RenderData, ...}`.

- [ ] Функц. тест: render data содержит идентичность+СИЗ-строки+журнал; readiness сообщает пропуски, не падает.
- [ ] `yarn dev` + браузер: сгенерировать Excel-карточку заполненного человека, открыть — данные на местах.
- [ ] Коммит.

## Финал Д3

- [ ] `./run check` зелёный; тест-БД мигрирована; cleanup style/phpstan.
- [ ] Браузерный смоук: учёт создаётся из требований должности; выдача со сканом; Excel-карточка.
- [ ] Нет `dd()`/`var_dump`/мёртвого кода; `.DS_Store` не в staged.
- [ ] Коммиты партиями (статус+калькулятор / агрегат+ORM / журнал+файл / документ). Пуш/мерж — по апруву.
- [ ] Открытые к пользователю (не блок): точный макет Excel-шаблона; поведение «по докам без срока» (принято Green).
