# Деплой «вынос проекции мониторинга из домена» — дизайн

**Дата:** 2026-10-17
**Контекст:** bounded context Compliance. Реализует §6 (промах 3) и §9 (Д4) спеки [compliance-journal-model-design.md](compliance-journal-model-design.md). Идёт ПОСЛЕ журнального деплоя (командные пути развязаны), rekey label→itemId и D5 (кросс-персон `WriteOffAct`) — все в main; D5 в PR #126. Прод НЕ пуст.

## 1. Цель и зачем

Проекция обязанности (`TrackedObligation`: даты/held/active + норменные/персональные метаданные) сегодня — **mapped Doctrine child-entity внутри агрегата `ProfileCompliance`**, cascade-persist/remove, и агрегат сам её мутирует на каждую запись факта (`recomputeObligation`), а `ComplianceProjectionRebuilder` досыпает норменные изменения. Дашборд/карточка/`/issue`/печать читают её, ГРУЗЯ АГРЕГАТ со всеми фактами и вычисляя производные на лету. Домен (`Domain/`) импортит read-сервисы `ComplianceStatusResolver`/`ObligationDueCalculator` — нарушение правила §6.3 «домен не знает про дашборд».

**Успех:** проекция — самостоятельная read-модель (своя таблица/репозиторий/проектор) ВНЕ агрегата; дашборд/карточка/`/issue`/сканер/печать читают её без загрузки агрегата; `ProfileCompliance` = журнал фактов (`FulfillmentRecord[]`) + персональные отклонения (декларации персональных позиций + `excludedKeys`), без read-сервисов и без `worstStatus()`; статус/bucket считается в ЕДИНСТВЕННОМ месте; дубли ФИО/формата-числа/порогов схлопнуты; прод-данные целы.

## 2. Ключевые решения (согласованы с заказчиком в брейншторме)

1. **bucket НЕ хранится — выводится на чтении.** В таблице проекции только время-независимые поля (`lastFulfilledAt`, `nextDueAt`, `heldQuantity`, `normQuantity`, `active`, метаданные). Ярлык bucket/статус — чистая функция `(строка проекции + now)` через ЕДИНЫЙ резолвер. Причина: bucket зависит от `now`, который меняется без события; хранимый bucket протух бы («скоро»→«просрочено» при переходе часов через `nextDueAt`) и требовал бы крон-rebuild. Вывод сравнением — всегда свежо, крона не надо, один источник правды. Фильтр «due-soon» — параметризованным SQL по `nextDueAt <= now + 60д` (как уже делает сканер). Данные крошечные (124 обязанности / 11 профилей) — стоимость вычисления ярлыка на чтении ничтожна. Это ОСОЗНАННЫЙ отход от буквы спеки §4.4 (там bucket материализуется).

2. **Запись проекции — ГИБРИД по признаку «одна строка vs fan-out», НЕ «выдача vs норма».**
   - **Горячий путь факта** (выдача, инструктаж, подпись списания) → затронутые строки проекции обновляются **СИНХРОННО write-through** через проектор в той же транзакции команды. Обновление 1–несколько строк — грошовое (факты уже в памяти команды, пара сравнений + UPSERT), основной процесс НЕ тормозит. Актор видит результат мгновенно; не зависит от воркера (на проде supervisor падает в FATAL, воркер поднимают руками — мёртвый воркер не должен врать дашбордом на горячем вводе).
   - **Тяжёлый fan-out** (смена нормы требования → десятки людей; переезд профиля; bulk) → **АСИНХРОННО по событию** (как сейчас `RecomputeOnRequirementChanged`/`RecomputeOnProfileSaved`), чтобы редкая админская операция не блокировалась пересчётом сотни строк. Дневной rebuild — страховка (уже есть).
   - Дорогое — это ЧТЕНИЕ (загрузка агрегата с фактами), и его мы убиваем материализацией в любом случае; запись одной строки дорогой не является, поэтому платить за неё eventual-consistency на горячем пути незачем.

3. **Переиспользуем существующую таблицу, не плодим новую.** `compliance_tracked_obligation` уже отдельная таблица с нужными полями и уже наполнена. Деплой = РАСЦЕПЛЕНИЕ: снять ORM-мэппинг «child агрегата» (cascade/inverse на `ProfileCompliance`), дать свой репозиторий + read-only entity для JOIN + проектор-писатель. Строку ключуем по `profileId` (Personnel) напрямую, чтобы читатели не трогали агрегат. Переименование в `compliance_obligation_projection` (имя из §4.4) — косметика, по желанию отдельной `ALTER … RENAME`; по умолчанию НЕ переименовываем (минимизируем миграцию). РЕШЕНИЕ НА РЕВЬЮ.

## 3. Отвергнутые подходы

- **Чисто асинхронно всё.** Максимальный декуплинг, но eventual-consistency на горячем вводе («только что выдал → вижу старое») + риск «мёртвый воркер → дрейф до дневного rebuild» на проде с нестабильным supervisor. Запись одной строки не настолько дорога, чтобы это оправдать.
- **Чисто синхронно всё.** Свежо и без воркера, но смена нормы (fan-out на десятки людей) блокировала бы админскую операцию тяжёлой транзакцией.
- **Хранить bucket колонкой (буква §4.4).** Фильтр/сорт по bucket прямо в SQL, но протухание + нужен крон-rebuild + два источника правды о статусе (хранимый vs живые даты). На нашем объёме выгода нулевая.
- **Новая таблица `compliance_obligation_projection` + копирующая миграция.** Лишний перенос данных, которые уже лежат в готовой таблице с теми же полями.

## 4. Дизайн по областям

### 4.1 Read-модель (таблица + entity)
- Таблица `compliance_tracked_obligation` (переиспользуем), поля (время-независимые): `id`, `profile_id` (Personnel, НОВОЕ — для чтения без агрегата; бэкфилл из `profile_compliance_id → profile_id`), `requirement_id`, `item_id`, `requirement_name`, `label`, `type`, `cadence`, `quantity`, `department_id`, `last_fulfilled_at`, `next_due_at`, `held_quantity`, `active`, `origin`, `mode`. Ключ обязанности `requirement_id|item_id`. **НЕТ** колонки bucket/status.
- Read-only Doctrine entity `ObligationProjection` (`read-only="true"`, по образцу Coatings `CoatingSearch`/`Coating.CoatingSearch.orm.xml`) — для DQL-чтений/JOIN на стороне запросов. Приложение её НЕ пишет напрямую через ORM — пишет проектор.

### 4.2 Проектор (пишущая сторона)
- `ComplianceObligationProjector` (Application/Service) — ЕДИНСТВЕННЫЙ писатель проекции. Метод уровня «пересобрать строки для (профиль[, требование])»: берёт живую норму (`RequirementRepository`), персональные декларации (с агрегата), журнал фактов (с агрегата), считает `lastFulfilledAt`/`nextDueAt`(через `ObligationDueCalculator`)/`heldQuantity`/`active`, UPSERT-ит строки, удаляет орфанов (норменные без фактов + вне нормы). Пишет через infra-репозиторий (DBAL `INSERT … ON CONFLICT DO UPDATE`, образец — `CoatingSystemSearchCacheRepository`) ИЛИ через ORM на read-only-entity-близнеце — РЕШЕНИЕ в плане (DBAL-upsert предпочтительнее: явная таблица, без ORM-владения).
- `ObligationDueCalculator` переезжает из `Domain/Service` в проектор-орбиту (Application) — он read-concern. `ComplianceProjectionRebuilder` ПОГЛОЩАЕТСЯ проектором (его desired-vs-facts + orphan-cleanup — это и есть материализация), либо становится тонкой обёрткой над ним для bulk.

### 4.3 Триггеры записи (гибрид)
- **Синхронно write-through:** командные хендлеры `RecordIssuanceCommandHandler`, `RecordInstructionCommandHandler`(если отдельный), `SignWriteOffActCommandHandler` после мутации фактов зовут `ComplianceObligationProjector->projectProfile(profileId, requirementId?)` в ТОЙ ЖЕ транзакции (messenger `doctrine_transaction` уже оборачивает command.bus). Агрегат проекцию больше НЕ мутирует.
- **Асинхронно по событию:** `RecomputeOnRequirementChangedHandler`, `RecomputeOnProfileSavedHandler`, `RecomputeOnWriteOffActSignedHandler` (fan-out) — репойнт с `ComplianceProjectionRebuilder` на проектор, остаются на асинхронном транспорте.
- **Backfill:** `app:compliance:rebuild-projection` репойнт на проектор (bulk-пересборка, страховка/ops). Дневной сканер due-soon не меняет механику.

### 4.4 Единый резолвер статуса
- `ComplianceBucketResolver` (переименование/консолидация `ComplianceStatusResolver`) — ЕДИНСТВЕННЫЙ расчёт bucket. Новый метод-над-строкой-проекции: `bucketFor(ObligationProjection $row, \DateTimeImmutable $now): ComplianceBucket` (вместо 6 позиционных аргументов в 4 местах). `DUE_SOON_DAYS=60`. Порог held<norm — ОДНА реализация (эпсилон `1e-9` канонизируется здесь, устраняя расхождение с `IssueAction::heldTone`). Все 4 call-site (`ProfileComplianceDTOTransformer`, `PersonRowDTOTransformer`, `DueSoonScanner`, `IssueAction`) зовут этот метод.
- `statusFor()` остаётся тонкой обёрткой `bucketFor → ComplianceStatus::fromBucket`.

### 4.5 Читатели (репойнт на read-модель)
- `ObligationProjectionRepository::findByFilter(ObligationProjectionFilter): PaginationResult` (bag-of-fields: `?profileIds`, `?requirementId`, `?departmentId`, `?type`, `?active`, due-soon-порог, `Pager`) — ЕДИНЫЙ метод чтения проекции (правило findByFilter). `findDueSoonObligations` (уже прямой скан) переезжает на эту таблицу/репозиторий.
- Репойнт: дашборд (`GetComplianceOverviewQueryHandler`, `GetPagedComplianceQueryHandler` → читать проекцию, не `findForDashboard` по агрегату), карточка (`GetProfileComplianceQueryHandler`), `/issue` (см. 4.7), печать (`RequirementCardProjector`/`DownloadCardAction` — читает строки проекции, не агрегат), preview, сканер. Ни один читатель проекции больше не грузит `ProfileCompliance`.
- `ProfileComplianceRepository::findForDashboard`/`findByProfile`-с-fetch-join-obligations — удаляются/упрощаются (агрегат грузится только там, где нужен журнал фактов: запись, история, списание).

### 4.6 Чистка домена
- `ProfileCompliance` ТЕРЯЕТ: коллекцию `obligations` (`TrackedObligation`) и её ORM-мэппинг; методы `putObligation`/`removeObligationByKey`/`recomputeObligation`/`recomputeAll`/`recomputeRequirement`/`worstStatus`; импорты `ComplianceStatusResolver`/`ObligationDueCalculator`.
- `ProfileCompliance` СОХРАНЯЕТ (это домен, не проекция): журнал `FulfillmentRecord[]`; `excludedKeys` (StringCollection); `cardNumber`; **декларации персональных обязанностей** — НОВЫЙ лёгкий VO `PersonalObligation(itemId, requirementId, label, type, cadence, ?quantity)` (список), т.к. факт «человеку добавили off-norm позицию» — доменное отклонение, его негде больше хранить (сейчас оно сидит в `TrackedObligation(origin=PERSONAL)` — переносим ДЕКЛАРАЦИЮ в VO, а её даты/held проектор материализует в строку проекции как origin=personal). `addPersonalObligation`/дедуп-по-label/`pruneOrphanPersonalObligations` остаются на агрегате, но оперируют VO-списком, не проекцией.
- Инвариант неизменности/количества/полноты инструктажа/№ карточки — на агрегате, как есть.

### 4.7 Чистка `/issue` (§7)
- Вынести ~170 строк сборки read-модели из `IssueAction` в Application view-builder/query (`GetIssuePageQuery`/`…ViewBuilder` либо расширить существующий query-путь). Контроллер — тонкий: собрать POST → команда; GET → query → render.
- Дедуп: формат числа — только `AmountFormatter` (убрать инлайн-копии в `ProfileComplianceDTOTransformer`, свериться с `Quantity::label`); ФИО — ОДИН хелпер (сейчас 7 копий: `PersonRowDTOTransformer`, `RequirementCardProjector`, `WriteOffActProjector`, `DueSoonScanner`, `IndexAction`, `WriteOffAct/ShowAction`, `EmitComplianceDueSoonCommand`) — вынести в общий (Personnel `ProfileDTO::fio()` или Shared-хелпер); порог held<norm — только в резолвере.

## 5. Миграция + ops

1. `ALTER TABLE compliance_tracked_obligation ADD COLUMN IF NOT EXISTS profile_id VARCHAR(64)`; бэкфилл `UPDATE … SET profile_id = (SELECT profile_id FROM compliance_profile_compliance pc WHERE pc.id = profile_compliance_id)`; затем NOT NULL + индексы (`profile_id`, `next_due_at`, `active`). Идемпотентно.
2. ORM: снять `one-to-many obligations`/`many-to-one profileCompliance` cascade с `ProfileCompliance`; завести read-only entity `ObligationProjection` на ту же таблицу.
3. После деплоя — `app:compliance:rebuild-projection` на проде (пересобрать проектором, подтвердить консистентность; строки уже есть, пересборка выравнивает поля/profile_id). Migrate-first, запись редкая/админская.
4. FK `profile_compliance_id` оставить временно (бэкфилл-источник), дропнуть позже отдельной миграцией вместе с cascade-семантикой — после подтверждения.

## 6. Тесты

- Проектор: выдача → строка проекции обновлена синхронно (held/nextDueAt/active); смена нормы → fan-out пересобрал затронутых; орфан-чистка; персональная декларация → строка origin=personal.
- Резолвер: `bucketFor(row, now)` — граничные (nextDueAt==now, now+59д vs now+61д, held<norm/held>=norm с эпсилоном); `statusFor` ladder.
- Читатели: дашборд/карточка/`/issue`/сканер отдают те же данные, читая проекцию без загрузки агрегата (функц., реальная БД); `findByFilter` фильтры+пагинация.
- Свежесть bucket без события: строка с `nextDueAt` в прошлом → `due`/`overdue` на чтении без всякого rebuild (пин анти-протухания).
- Домен: `ProfileCompliance` без проекции/резолвера компилируется; персональная декларация round-trip; `excludedKeys` цел.
- Миграция: бэкфилл `profile_id`; rebuild выравнивает.

## 7. Вне охвата (non-goals)

- Хранение bucket колонкой (осознанно нет, §2.1).
- Переименование таблицы в `compliance_obligation_projection` (косметика, опционально).
- D6 (ответственные/комиссия→profileId), Вариант B (сгорание расходника), card_number NOT NULL — отдельные деплои.
- Переосмысление дашборд-агрегаций (`ComplianceBucketResolver` как единый источник — да; новые метрики — нет).

## 8. Риски

- **Гибрид-двоепутие записи:** синхронный write-through и асинхронный fan-out должны сходиться к одному результату. Митигируем: оба зовут ОДИН проектор (одна логика материализации), дневной rebuild выравнивает дрейф.
- **Персональные декларации при расцеплении:** потерять их = потерять off-norm позиции людей. Митигируем: перенос ДЕКЛАРАЦИИ в доменный VO до снятия `TrackedObligation`, миграция/тест на round-trip.
- **Читатели, забытые при репойнте:** строка проекции, прочитанная ещё через агрегат. Митигируем: греп всех `getObligations()`/`findForDashboard`/`findByProfile`-с-obligations, финальное ревью.
- **profile_id бэкфилл на проде:** если у строки нет соответствующего `profile_compliance` — осиротевшая строка. Пред-проверка числа несматченных (ожидаем 0 на 124).

## 9. Декомпозиция

Один деплой, один план (через writing-plans). Ориентир порядка задач (TDD): (1) read-only entity + `profile_id` колонка/бэкфилл-миграция; (2) `ComplianceBucketResolver` единый + репойнт 4 call-site; (3) `ComplianceObligationProjector` + infra-репозиторий (поглощает `ComplianceProjectionRebuilder`); (4) синхронный write-through в командных хендлерах + снятие мутации проекции с агрегата; (5) `PersonalObligation` VO + перенос деклараций; (6) асинхронные event-хендлеры + `rebuild-projection` на проектор; (7) `ObligationProjectionRepository::findByFilter` + репойнт читателей (дашборд/карточка/сканер/печать/preview); (8) чистка `/issue` в view-builder + дедуп ФИО/формата; (9) снятие read-сервисов/`worstStatus` с домена + удаление мёртвого; data-миграция/ops. Детализация — в плане.
