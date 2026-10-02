# Деплой 3: учёт по человеку (событийная проекция), журнал выдач, трекинг сроков, документ-карточка

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development или superpowers:executing-plans. Шаги — чекбоксы.
>
> Соседи: `-1-personnel.md` (нужен: `Profile`, `Position`, `Department`), `-2-requirements.md` (нужен: `Requirement`, `RequirementItemInterface`/`MaterialItem`/`NonMaterialItem`, `ComplianceType` Material/NonMaterial, `Cadence`/`CadenceKind`(Once/Periodic/ByFact/ByManufacturerDoc)/`PeriodUnit`, `Quantity`). Спека: `docs/plans/compliance-siz-design.md`. Самодостаточен.

**Цель:** учёт обеспеченности по человеку. Факты выдачи/прохождения — источник истины (`FulfillmentRecord`). Обязанности человека — **событийная проекция** (`TrackedObligation`): выводятся из **текущих** требований его должности + личных отклонений, но материализуются строками в БД и обновляются по событиям (не морозим норму, ручного ресинка нет). Дата следующего срока (`nextDueAt`) хранится и пересчитывается по событиям; **статус (цвет) не хранится — выводится на чтении** из дат + `now`. Документ — личная карточка на (человек × требование), генерится по запросу.

## Ключевые решения (согласовано, приоритет над старым текстом)

1. **Subject = `profileId`** (Personnel `Profile`), НЕ `userUlid`. Профиль даёт должность (→ требования) и идентичность (ФИО/размеры/таб.№ → документ). `userUlid` нужен только на момент уведомления (Д4) — резолвим `Profile.userUlid`.
2. **Живая норма + событийная проекция.** Значения нормы (cadence/quantity/label) НЕ заморожены: при пересчёте читаем из текущего `Requirement`. В проекции храним снимок для показа + РЕЗУЛЬТАТ (`nextDueAt`), обновляемый по событиям. Ручного ресинка/`inNorm`-заморозки НЕТ.
3. **Даты — хранимые и событийные; статус — производный.** `lastFulfilledAt`/`nextDueAt` меняются только по событиям (выдача; смена cadence в норме). Цвет (Green/Yellow/Red) меняется от хода времени → не колонка, выводим из дат+`now` (в т.ч. SQL-ом для дашборда/алерта Д4).
4. **Одна должность — в нескольких требованиях** (из Д2, без уникальности). Обязанности профиля = union позиций всех требований, покрывающих его должность. Нужен `RequirementRepository::findByPositionId(): Requirement[]` (jsonb `@>`, GIN-индекс уже есть — добавить метод, в Д2 сознательно отложили).
5. **Тип позиции — Material/NonMaterial** (из класса item). Материальная выдача несёт количество/%износа/возврат; процедура — только дату (+результат/скан). `FulfillmentRecord` — поля материальной части nullable.
6. **Дата выдачи — на каждый item.** В форме по умолчанию = дата документа (одна), админ переопределяет построчно.
7. **Документ — хранимый артефакт на (профиль × требование)** с минимальным жизненным циклом: **нет → сформирован → подписан**. «Сформировать» = сгенерировать xlsx (шаблон по `requirement.type`: Material → образец; NonMaterial позже, шаблона нет) + сохранить файл. «Сформирован» можно переформировать; «Подписан» = приложен скан → **неизменяем** (edit-lock как у отчёта — `isEditable()` решает агрегат; подпись = заморозка). На карточке человека видно, каких документов нет; действия: сформировать / переформировать / приложить скан + массовое **«сформировать всё недостающее»** (материальные требования профиля без документа). Никаких `Ppe*`-имён (легаси старого плана).
8. **Исполнение — только у ПОДПИСАННЫХ; неподписанное = НЕ ИСПОЛНЕНО (Red).** Документ (человек×требование) не подписан ⇒ требование не исполнено → в светофоре **Red** (по факту отсутствия подписи, не по сроку). Подписан ⇒ срок-светофор по датам выдачи. **Срок-алерты** (напоминание о продлении) — только по подписанным (`active`); неподписанные краснят на дашборде как «не исполнено — нет подписи» (отдельный нудж «подпишите» — открытый вопрос Д4). Денорм `active` на `TrackedObligation` = документ подписан; ставится при подписи (событие) и при пересборке. Резолвер: `!active → Red`.

**Архитектура:** `ProfileCompliance` — корень (один на `profileId`), хранит журнал `FulfillmentRecord` (факты) + личные отклонения (добавленные/исключённые обязанности). `TrackedObligation` — **реляционная проекция** (индексы `next_due_at`/`type`/`department`/`profile_id` для Д4), пересобирается доменным сервисом из живой нормы + фактов по событиям. Документ — shared `TemplateRendering` (PhpSpreadsheet), проектор+шаблон+readiness (паттерн Reports). Скан — shared File-backbone (stage→promote, `FilePurpose`).

**Tech Stack:** как Д1/Д2 + `App\Shared\Infrastructure\Service\TemplateRendering`, `App\Shared\Domain\File\{FileStorage,FilePurpose,FileConstraints}`, Messenger (события-пересчёт).

## Global Constraints

- (Все ограничения Д1/Д2: VO+`AppException` RU, правила в домене, marker-interface хендлеры, id из вне, тонкие per-action контроллеры, `ComplianceAccessControl` (запись — админ, чтение — авторизованным), тесты зеркалят `src/`+функц. с реальной БД+`authenticateAsSystem()`, фронт сборка+браузер, стили копируем, поиск/список через `findByFilter`+фильтр, фронт-поиск через общий `typeahead`, `./run check` в контейнере, миграции идемпотентные, коммиты по задаче/пуш по апруву.)
- **Статус НЕ хранится** — только `lastFulfilledAt`/`nextDueAt` в БД, цвет выводится. Никакой ночной пересборки статусов.
- **Проекция — производная**: источник истины = факты (`FulfillmentRecord`) + текущая норма + личные отклонения. При рассинхроне спасает служебная пересборка.
- Скан-файл: `ownerId = ProfileCompliance.id` (или fulfillment id), UUID `StoredFile` строкой; обязателен `FileAccessControl` (иначе `/cabinet/file/{uuid}` → 403 deny-by-default).

## Review Focus

- **Первая выдача** → `lastFulfilledAt`/`nextDueAt` проставлены из cadence; статус на чтении уходит из Red в Green/Yellow (тест T2/T4).
- **Правка/удаление последнего факта** → `lastFulfilledAt`/`nextDueAt` пересчитаны по оставшимся фактам, не «залипают» (тест T5).
- **Смена cadence в норме** → событие пересчитывает `nextDueAt` у всех покрытых профилей от ИХ последней выдачи (766н: срок от даты выдачи); проекция актуальна без ручного ресинка (тест T4).
- **Позицию убрали из нормы, а факты есть** → строку проекции убираем/помечаем, но `FulfillmentRecord` сохраняются и видны в «прочие/вне нормы» (тест T4).
- **`ByFact` (по факту/износ)** → `nextDueAt=null`; выполнено→Green, не выполнено→Red; по дате не краснеет (тест T2).
- **`ByManufacturerDoc`** → `nextDueAt` = ручная дата с выдачи; без даты → Green (тест T2/T5).
- **Осиротевший скан** — при удалении факта промоутнутый файл удаляется (`removeDetachedFiles`) (тест T5).
- **Документ при неполных данных** (профиль без размеров, если шаблон требует) → readiness сообщает «чего не хватает», не 500 (тест T6).

---

## T1. `ComplianceStatus` (enum) + вывод статуса

**Файлы (новые):**
- `app/src/Compliance/Domain/Type/ComplianceStatus.php` — `enum ComplianceStatus: string { Green; Yellow; Red; }` + `title()`, `worseOf(self,self)`, `severity(): int`.
- `app/src/Compliance/Domain/Service/ComplianceStatusResolver.php` — **чистая функция** `statusFor(bool $active, ?\DateTimeImmutable $lastFulfilledAt, ?\DateTimeImmutable $nextDueAt, \DateTimeImmutable $now): ComplianceStatus`: `!active`→Red (документ не подписан — не исполнено); `lastFulfilledAt===null`→Red; `nextDueAt===null`→Green (выполнено, срока нет — Once/ByFact/по-докам-без-даты); `nextDueAt < now`→Red; `nextDueAt <= now+DUE_SOON_DAYS(14)`→Yellow; иначе Green. Now — параметром (тестируемость).

- [ ] Юнит: `worseOf` (Red доминирует); `statusFor` — не подписан→Red, не выдано→Red, просрочено→Red, 10 дней→Yellow, 60→Green, граница 14д→Yellow, `nextDueAt=null`+выдано→Green.
- [ ] Коммит.

## T2. `ObligationDueCalculator` (nextDueAt по cadence, 4 вида)

**Файл (новый):** `app/src/Compliance/Domain/Service/ObligationDueCalculator.php` — `nextDue(Cadence $c, ?\DateTimeImmutable $lastFulfilledAt, ?\DateTimeImmutable $manualDueDate): ?\DateTimeImmutable`:
- `lastFulfilledAt===null` → `null` (не выдано; Red даст резолвер по пустому lastFulfilled);
- `Periodic` → `c->nextDueFrom(lastFulfilledAt)` (уже учитывает число+`PeriodUnit`);
- `Once` → `null` (выполнено навсегда);
- `ByFact` → `null` (авто-срока нет, меняется по факту);
- `ByManufacturerDoc` → `manualDueDate` (введён на выдаче; если null → null → Green).

- [ ] Юнит: Periodic (год/мес) даёт дату; Once/ByFact→null при выданном; ByManufacturerDoc→manualDueDate; не выдано→null.
- [ ] Коммит.

## T3. Агрегат `ProfileCompliance` + `TrackedObligation` (проекция) + `FulfillmentRecord` (факт)

**Файлы (новые):**
- `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php` — `class ProfileCompliance extends Aggregate`. Поля: `Uuid $id`, `string $profileId`, `Collection $obligations` (`TrackedObligation`, one-to-many cascade — ПРОЕКЦИЯ), `Collection $records` (`FulfillmentRecord`, one-to-many cascade — ФАКТЫ), личные отклонения (`StringCollection $excludedKeys` — исключённые `requirementId|label`; `TrackedObligation[]`-добавленные помечены `origin=personal`), `createdAt/updatedAt`, `int $version`. Один на `profileId`. Методы: `recordFulfillment(...)`, `editRecord`/`removeRecord`, `addPersonalObligation(...)`/`excludeObligation(key)`, `worstStatus(ComplianceStatusResolver, ?ComplianceType, now)` (для карточки человека).
- `app/src/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligation.php` — сущность-ПРОЕКЦИЯ: `Uuid $id`, ссылка на root, `string $requirementId`, `string $requirementName`, `string $label`, `ComplianceType $type`, `Cadence $cadence`, `?Quantity $quantity`, `string $departmentId` (денорм из профиля, для Д4), `?\DateTimeImmutable $lastFulfilledAt`, `?\DateTimeImmutable $nextDueAt`, `bool $active` (документ требования подписан — только тогда трекаем сроки/алерты; индексируется), `string $origin` (norm|personal). **Статус не хранит.** `key(): string` = `requirementId.'|'.mb_strtolower(label)`.
- `app/src/Compliance/Domain/Aggregate/ProfileCompliance/FulfillmentRecord.php` — ФАКТ: `Uuid $id`, ссылка на root, `string $obligationKey` (requirementId|label — переживает пересборку проекции), `\DateTimeImmutable $fulfilledAt`, `?Quantity $quantity`, `?Percent $wearPercent`, `?string $note`, `?string $fileId`, `?\DateTimeImmutable $manualDueDate` (для ByManufacturerDoc), `?\DateTimeImmutable $returnedAt`, `?Quantity $returnedQuantity`.
- `Percent` — reuse shared VO (проверить путь, иначе завести [0;100]).
- Репозиторий `ProfileComplianceRepositoryInterface` (`add`, `findByProfile(string): ?ProfileCompliance`, `findObligationsToNotify(now)` — индексный SELECT для Д4, только `active=true` + просрочено/скоро/не выдано) + реализация. ORM: root + `compliance_tracked_obligation` (индексы `profile_id`,`next_due_at`,`type`,`department_id`) + `compliance_fulfillment_record` (индекс `profile_id`,`obligation_key`). VO `Cadence`/`Quantity`/`Percent` — jsonb-типы. **Fetch-join** obligations/records при загрузке (readonly-id+to-one → addSelect).

- [ ] Миграции: `compliance_profile_compliance`, `compliance_tracked_obligation`, `compliance_fulfillment_record` (индексы выше). Идемпотентно.
- [ ] Функц. тест агрегата: `recordFulfillment` кладёт факт, привязывает по `obligationKey`; `worstStatus` считает по проекции+резолверу; удаление последнего факта откатывает `lastFulfilledAt`.
- [ ] Коммит.

## T4. Событийная пересборка проекции (замена ручного ресинка)

**Файлы (новые):**
- `app/src/Compliance/Application/Service/ComplianceProjectionRebuilder.php` — `rebuildForProfile(string $profileId)`: тянет профиль (`GetProfile...` из Personnel через шину → positionId+departmentId); `RequirementRepository::findByPositionId(positionId)` → union `RequirementItemInterface` всех покрывающих требований; минус `excludedKeys`, плюс personal-обязанности; для каждой позиции строит/обновляет `TrackedObligation` (снимок label/type/cadence/quantity/requirementId+name/department), `lastFulfilledAt` = макс `fulfilledAt` фактов по `obligationKey`, `nextDueAt = ObligationDueCalculator->nextDue(...)`, `active` = документ (profileId,requirementId) в статусе `Signed`; исчезнувшие из нормы позиции с фактами — помечает `origin` вне нормы/оставляет строку без нормы, без фактов — удаляет.
- **События пересчёта:**
  - `SaveRequirementCommandHandler` (Д2) → диспатчит `RecomputeComplianceForRequirementMessage(requirementId)` (async); handler `RecomputeComplianceForRequirementHandler` — по requirement.positionIds находит профили (Personnel-шина) → `rebuildForProfile` каждому.
  - Personnel: при смене должности/создании профиля диспатчит `ProfileChangedMessage(profileId)` (shared/app-событие); handler `RecomputeComplianceForProfileHandler` → `rebuildForProfile`.
  - Изменение фактов (T5) — пересчёт затронутой строки инлайн в команде (не полный rebuild).
  - Документ подписан (T6) → выставить `active=true` у строк этого требования профиля (без полного rebuild); переоформление/расподпись (если разрешим) → `active=false`.
- Служебная команда `app:compliance:rebuild-projection [profileId?]` — страховка при пропущенном событии.
- Query `GetProfileCompliance(profileId): ProfileComplianceDTO` (obligations+records как вложенные DTO; статус в DTO вычислен резолвером на момент запроса).

- [ ] Функц. тесты: `rebuildForProfile` строит из нескольких требований; смена cadence в норме → событие пересчитало `nextDueAt`, журнал цел; позиция убрана+есть факт → строка помечена вне нормы, факт цел; personal add/exclude.
- [ ] Коммит.

## T5. Журнал выдач/прохождений + скан + контроллеры

**Файлы (новые):**
- `app/src/Compliance/Domain/File/RequirementScanPurpose.php` — `enum … implements FilePurpose { case Scan='scan'; }` (`storagePrefix()`→`'compliance/scan'`, `key()`→`'compliance.scan'`, `constraints()`→pdf/jpeg/png, 10 МБ). Без «Ppe».
- `app/src/Compliance/Application/Service/AccessControl/RequirementScanFileAccessControl.php` — `implements FileAccessControl` (тег): `supports`=key; `canView` грузит `ProfileCompliance` по `f->ownerId()`, делегирует `ComplianceAccessControl`.
- Command `RecordIssuanceCommand(profileId, requirementId, documentDate, list<ItemFulfillment>{obligationKey, date?(деф=documentDate), quantity?, wearPercent?, manualDueDate?, stagedFileId?}, note?)` + Handler (`canManage`): промоут файлов, `person->recordFulfillment(...)` по каждой позиции с ЕЁ датой (дефолт = documentDate), пересчёт затронутых строк проекции, удалить открепл. файлы. `EditFulfillmentCommand`, `RemoveFulfillmentCommand` (+ `removeDetachedFiles`).
- Контроллеры `Infrastructure/Controller/Fulfillment/{RecordAction,EditAction,DeleteAction}.php` — тонкие; форма выдачи: одна дата документа по умолчанию + построчное переопределение; скан через `/cabinet/file/stage` (до хендлера доходит uuid). Материальная позиция — кол-во/износ; процедура — только дата. Фронт-строки — `list-rows`/typeahead-паттерн, стили копируем.

- [ ] Функц. тесты: record с per-item датами (дефолт=documentDate, переопределение), stage→promote (ownerId), remove факта удаляет открепл. файл + откат `nextDueAt`, ByManufacturerDoc с ручной датой → `nextDueAt` = она.
- [ ] `yarn dev` + браузер: зафиксировать выдачу требования человеку (дефолт-дата, одну позицию переопределить), со сканом; удалить — файл исчез, статус пересчитан.
- [ ] Коммит.

## T6. Документ — жизненный цикл (профиль × требование). БЕЗ генерации файла

Решение (согласовано 2026-09-30): «сделаем цикл в приложении, обкатаем, потом займёмся шаблоном».
Генерация xlsx-бланка (шаблон/`RequirementCardProjector`/readiness/скачивание бланка) вынесена в
отдельный шаг **T7 (отложен)**. Здесь — только цикл `нет → сформирован → подписан`, делающий статусы
рабочими: подписан ⇒ `active=true` ⇒ открывается трекинг сроков; не подписан ⇒ Red («не исполнено»).

**Модель:** `RequirementDocument` — **дочерняя сущность `ProfileCompliance`** (как `TrackedObligation`),
не отдельный агрегат. Подпись и `setActiveForRequirement(true)` — атомарно в одном агрегате, один репозиторий.

**Файлы (новые):**
- `app/src/Compliance/Domain/Aggregate/ProfileCompliance/DocumentStatus.php` — `enum DocumentStatus: string { Formed; Signed; }` + `title()`.
- `app/src/Compliance/Domain/Aggregate/ProfileCompliance/RequirementDocument.php` — дочерняя сущность: `Uuid $id`, ссылка на root, `string $requirementId`, `DocumentStatus $status`, `?string $scanFileId`, `?\DateTimeImmutable $signedAt`, `createdAt/updatedAt`. `isEditable(): bool = status !== Signed`. `markSigned(string $fileId)` → бросает `AppException`, если уже `Signed`; иначе `Signed`+`scanFileId`+`signedAt`. Статуса-строки в БД — enum-type XML.
- ORM `app/src/Compliance/Infrastructure/Database/ORM/Aggregate/ProfileCompliance.RequirementDocument.orm.xml` + one-to-many в `ProfileCompliance.ProfileCompliance.orm.xml` (`documents`, mapped-by, orphan-removal, cascade persist+remove; many-to-one back-ref, join on-delete CASCADE).
- Миграция `compliance_requirement_document` (`id`, `profile_compliance_id` FK CASCADE, `requirement_id`, `status`, `scan_file_id` NULL, `signed_at` NULL, `created_at`, `updated_at`; уник. индекс `(profile_compliance_id, requirement_id)` — один документ на требование у человека; индекс `requirement_id`). Идемпотентно.

**Методы `ProfileCompliance` (новые):**
- `documentFor(string $requirementId): ?RequirementDocument`.
- `formDocument(string $requirementId, Uuid $id, now)`: если документа нет — создаёт `Formed`; если есть `Formed` — no-op; если `Signed` — `AppException` («карточка подписана, переоформление позже»).
- `signDocument(string $requirementId, string $fileId, now)`: грузит/создаёт документ, `markSigned(fileId)`, затем `setActiveForRequirement(requirementId, true)` (внутри агрегата). Идемпотентность подписи — через `markSigned`.

**Application:**
- Command `FormRequirementDocumentCommand(profileId, requirementId)` + Handler (`canManage`): `findByProfile`, `formDocument`, save.
- Command `FormMissingDocumentsCommand(profileId)` + Handler: по всем `requirementId` из obligations, где документа ещё нет — `formDocument` каждому (пакетно, «сформировать всё недостающее»).
- Command `AttachSignedScanCommand(profileId, requirementId, stagedFileId)` + Handler (`canManage`): промоут staged→fileId (`RequirementScanPurpose::SignedCard`), `signDocument`, save. При замене — снять старый скан из хранилища.
- `GetProfileCompliance` дополняется: в `ProfileComplianceDTO` — `documents: array<requirementId, {status:string, scanDownloadUrl:?string}>`; трансформер читает `pc.documents`.

**Infrastructure (тонкие per-action контроллеры):**
- `Infrastructure/Controller/Document/FormAction.php` (POST) → `FormRequirementDocumentCommand`, flash+redirect на карточку.
- `Infrastructure/Controller/Document/FormMissingAction.php` (POST) → `FormMissingDocumentsCommand`.
- `Infrastructure/Controller/Document/AttachScanAction.php` (POST, staged uuid) → `AttachSignedScanCommand`.
- `Infrastructure/Controller/Document/DownloadScanAction.php` (GET) → стрим подписанного скана через `FileStorage` (гейт `ComplianceFileAccessControl`).

**UI (карточка `show.html.twig`, идиом списка покрытий — уже переделан):** в шапке секции требования — статус документа (`нет` / `сформирован` / `подписан`, пилюлей-вердиктом/тегом) и действия под `canEdit`:
- нет документа → кнопка «Сформировать документ» (POST FormAction);
- `Formed` → «Приложить подписанную карточку» (staged-file upload → AttachScanAction);
- `Signed` → «Скачать скан» + иконка-замок.
- В шапке страницы — «Сформировать всё недостающее» (FormMissingAction).

- [x] Юнит (агрегат): `formDocument` (нет→Formed, Formed→no-op, Signed→AppException); `signDocument` → Signed+active=true, повторная подпись → AppException; заморозка журнала (`recordFulfillment`) и рядов (`removeObligationByKey`) подписанного → AppException; `requirementIdsWithoutDocument`.
- [x] Функц. (реальная БД): `FormRequirementDocument` персистит Formed (active=false); `AttachSignedScan` промоутит скан → Signed+scanFileId+active=true; `FormMissingDocuments` заводит недостающие. Rebuilder пропускает подписанное (строки заморожены).
- [x] `./run check` зелёный (613 тестов); обе БД мигрированы (Version20260930160000).
- [ ] Браузер: сформировать документ по требованию → приложить скан → статус строк стал зелёным/жёлтым (трекинг открылся); повторно приложить нельзя.
- [ ] Коммит (по апруву).

**Инвариант заморозки (ответ на «у нас так?»):** подписанная карточка не меняется — новых/удалённых рядов быть не может. Правило живёт в `RequirementDocument::assertMutable()` (класс документа); агрегат спрашивает документ на КАЖДОЙ мутации ряда: журнал (`recordFulfillment`/`removeRecord`) И проекция (`putObligation`/`removeObligationByKey`). Rebuilder подписанные требования пропускает целиком.

## T7 (отложен). Генерация xlsx-бланка карточки

По готовности цикла: шаблон `requirement_card_material.xlsx` (образец пользователя), `RequirementCardProjector`
(идентичность+позиции+журнал через `RepeatValue`), `RequirementCardReadinessChecker` (`TemplateRendering::validate()`),
`GenerateRequirementDocumentAction(profileId, requirementId)` (стрим бланка), кнопка «Скачать бланк» на `Formed`.
NonMaterial-шаблон — ещё позже. Отдельный план при старте.

## Финал Д3

- [ ] `./run check` зелёный; тест-БД мигрирована; cleanup style/phpstan.
- [ ] Браузер: проекция строится из требований должности; выдача с дефолт-датой+переопределением+сканом; статус на чтении корректен; карточка генерится.
- [ ] Нет `dd()`/`var_dump`/мёртвого кода; никаких `Ppe*`-имён; `.DS_Store` не в staged.
- [ ] Коммиты партиями (статус+due-калькулятор / агрегат+ORM / событийная пересборка / журнал+скан / документ). Пуш/мерж — по апруву.
- [ ] Открытые к пользователю (не блок): цикл/«подписан» документа (морозить позже); шаблон NonMaterial-документа; auto-флаг «недовыдано по количеству» (позже).

## Пометка для Д4 (учесть при обновлении `-4-`)

- Дашборд «по людям» (главный) + отделы/требования; статус — на чтении из `active`+`nextDueAt`+`now` (SQL-бакеты), не хранится. Неподписанное требование → Red «не исполнено» (не по сроку). Срок-алерты — только по `active=true`.
- **Состояние фильтра — в URL** (shareable, конвенция проекта); алерт-уведомление формирует **глубокую ссылку** на дашборд `?profile=<profileId>&status=…` — клик открывает панель на человеке (чип по id).
- **Алерт-проход раз в сутки НИЧЕГО не считает** — индексный `SELECT` по `next_due_at` (просрочено/скоро + не выдано) с дедупом `lastNotifiedAt`; `symfony/scheduler` только триггерит. Уведомление шлём ответственному + сотруднику (`Profile.userUlid`).
- Макет панели (согласован): https://claude.ai/code/artifact/4711ebc6-c07d-4e35-bbff-bc83ad42659d
