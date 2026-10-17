# Вынос проекции мониторинга Compliance — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Вынести проекцию обязанностей (`TrackedObligation`) из агрегата `ProfileCompliance` в самостоятельную read-модель (новая таблица + проектор + единый резолвер), чтобы дашборд/карточка/`/issue`/сканер/печать читали её без загрузки агрегата, а домен остался журналом фактов + персональных отклонений.

**Architecture:** Expand/contract. Новая таблица `compliance_obligation_projection` наполняется `ComplianceObligationProjector` (DBAL-upsert, образец — Coatings `CoatingSystemSearchCacheRepository`) параллельно старой `compliance_tracked_obligation`; запись гибридная — синхронно write-through на горячем пути факта, асинхронно по событию на fan-out; bucket не хранится, считается единым `ComplianceBucketResolver::bucketFor(ObligationView, now)`; читатели перецепляются по одному; в финале старая таблица снимается с агрегата и дропается.

**Tech Stack:** PHP 8.5, Symfony, Doctrine ORM (XML mapping) + DBAL, Messenger (`doctrine_transaction` на command.bus, async-транспорт на событиях), PHPUnit (unit на хосте, functional в контейнере), PostgreSQL.

**Spec:** `docs/plans/compliance-projection-extraction-design.md`

## Global Constraints

- Домен (`Compliance/Domain/`) НЕ знает про дашборд: после деплоя `ProfileCompliance` не импортит `ComplianceStatusResolver`/`ObligationDueCalculator` и не несёт проекцию/`worstStatus` (спека §6.3, §4.6).
- bucket/status НИКОГДА не хранится в таблице — только выводится на чтении из `(строка + now)` единым резолвером (спека §2.1).
- Запись проекции — гибрид по признаку «одна строка vs fan-out»: горячий путь факта синхронно write-through в транзакции команды; норма/профиль/bulk асинхронно по событию (спека §2.2).
- Поиск/листинг проекции — ОДИН `ObligationProjectionRepository::findByFilter(ObligationProjectionFilter): PaginationResult` через `Pager::fromPage` → `{items,page,hasMore}`; не плодить `suggest()/findByX()` (корневой CLAUDE.md).
- Проектор — ЕДИНСТВЕННЫЙ писатель новой таблицы (DBAL-upsert), приложение не пишет read-only entity через ORM.
- `DUE_SOON_DAYS = 60` — одна константа, один порог (общий для дашборда/карточки/прохода, см. `feedback_compliance_status_single_source`).
- id агрегатов/сущностей передаётся параметром, генерится в maker/handler, не в конструкторе (CLAUDE.md).
- Тесты: unit зеркалят `src/`; Application/Infra — functional с реальной БД в контейнере (`docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit …`; test_db эфемерна — migrate перед прогоном). Гейт конца задачи — полный `./run check` (style + phpstan L6 + unit + functional) зелёный.
- Миграции идемпотентны (`IF NOT EXISTS`, guarded). Прод НЕ пуст (≈124 строки проекции, 11 профилей, 3 требования).
- Никаких эмоджи; русские пользовательские строки; тонкие контроллеры; копировать стиль ближайшего аналога; не оставлять мёртвый код.

## Review Focus

- **Свежесть bucket без события записи:** строка с `nextDueAt` в прошлом и без новых фактов обязана читаться как «просрочено» (не «скоро») — покрыто тестом резолвера в Task 2 (строка с `nextDueAt < now` → `OVERDUE` без всякого rebuild).
- **Расхождение параллельного прогона:** новая и старая таблицы не должны разъезжаться до флипа — паритет-тест в Task 3 (после выдачи строка новой таблицы совпадает с тем, что считал бы агрегат).
- **Потеря персональных (off-norm) деклараций при расцеплении:** человек с добавленной вне-нормы позицией не должен её лишиться — round-trip-тест `PersonalObligation` в Task 5 (добавить → снять старую коллекцию → декларация и её проекционная строка целы).
- **Забытый читатель на старом пути:** любой экран, всё ещё грузящий `ProfileCompliance::getObligations()` после флипа, отдаст устаревшее/пустое — греп-проверка в Task 4 + финальное ревью (ни одного чтения проекции через агрегат).
- **Гонка подписи списания с проекцией:** подпись кросс-персон акта fan-out синхронно пишет проекцию нескольких людей в одной транзакции — тест в Task 3 (подпись на 2 человек → обе строки проекции обновлены атомарно).

---

## File Structure

**Новое:**
- `app/src/Compliance/Domain/ValueObject/ObligationView.php` — узкий интерфейс для резолвера (поля, от которых зависит bucket).
- `app/src/Compliance/Domain/Aggregate/ProfileCompliance/PersonalObligation.php` — VO декларации off-norm позиции (Task 5).
- `app/src/Compliance/Infrastructure/Database/ReadModel/ObligationProjection.php` — read-only Doctrine entity на новую таблицу.
- `app/src/Compliance/Infrastructure/Database/ORM/ReadModel/ObligationProjection.orm.xml` — маппинг `read-only="true"`.
- `app/src/Compliance/Domain/Repository/ObligationProjectionRepositoryInterface.php` + `ObligationProjectionFilter.php`.
- `app/src/Compliance/Infrastructure/Repository/ObligationProjectionRepository.php` — чтение (DQL на read-only entity) + DBAL-upsert запись.
- `app/src/Compliance/Application/Service/ComplianceObligationProjector.php` — единственный писатель проекции.
- `app/src/Compliance/Application/UseCase/Query/GetIssuePage/…` — view-builder/query для `/issue` (Task 6).
- Миграции: `VersionNNNNNN_a` (CREATE новой таблицы), `VersionNNNNNN_z` (DROP старой, Task 7).

**Модифицируется:**
- `app/src/Compliance/Domain/Service/ComplianceStatusResolver.php` → `ComplianceBucketResolver` (единый `bucketFor(ObligationView,now)`).
- `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php` — снятие проекции/recompute*/worstStatus/read-сервисов; +`PersonalObligation[]`.
- `app/src/Compliance/Application/Service/ComplianceProjectionRebuilder.php` — поглощается проектором/становится обёрткой.
- Event-хендлеры `RecomputeOn*Handler`, `RecomputeProfileCommandHandler`, console `RebuildComplianceProjectionCommand` — репойнт на проектор.
- Командные хендлеры `RecordIssuanceCommandHandler`, `RecordInstructionCommandHandler` (если отдельный), `SignWriteOffActCommandHandler` — sync write-through.
- Читатели: `GetComplianceOverviewQueryHandler`, `GetPagedComplianceQueryHandler`, `GetProfileComplianceQueryHandler`, `IssueAction`, `RequirementCardProjector`/`DownloadCardAction`, `PreviewAction`, `DueSoonScanner` — на новую read-модель.
- Трансформеры `ProfileComplianceDTOTransformer`, `PersonRowDTOTransformer` — через единый резолвер; дедуп ФИО/формата.

---

## Task 1: Expand — новая таблица проекции + read-only entity + репозиторий чтения

**Files:**
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20261101120000.php`
- Create: `app/src/Compliance/Infrastructure/Database/ReadModel/ObligationProjection.php`
- Create: `app/src/Compliance/Infrastructure/Database/ORM/ReadModel/ObligationProjection.orm.xml`
- Create: `app/src/Compliance/Domain/Repository/ObligationProjectionRepositoryInterface.php`
- Create: `app/src/Compliance/Domain/Repository/ObligationProjectionFilter.php`
- Create: `app/src/Compliance/Infrastructure/Repository/ObligationProjectionRepository.php`
- Test: `app/tests/Functional/Compliance/Infrastructure/Repository/ObligationProjectionRepositoryTest.php`

**Interfaces:**
- Produces:
  - Таблица `compliance_obligation_projection`: `id UUID PK`, `profile_id VARCHAR(64) NOT NULL`, `requirement_id VARCHAR(64) NOT NULL`, `item_id VARCHAR(64) NOT NULL`, `requirement_name VARCHAR(255)`, `label VARCHAR(1000) NOT NULL`, `type VARCHAR(32) NOT NULL`, `cadence VARCHAR(32) NOT NULL`, `quantity DOUBLE PRECISION NULL`, `department_id VARCHAR(64) NULL`, `last_fulfilled_at TIMESTAMP(0) NULL`, `next_due_at TIMESTAMP(0) NULL`, `held_quantity DOUBLE PRECISION NOT NULL DEFAULT 0`, `active BOOLEAN NOT NULL DEFAULT true`, `origin VARCHAR(16) NOT NULL`, `mode VARCHAR(16) NULL`. Уник `(profile_id, requirement_id, item_id)`; индексы `profile_id`, `next_due_at`, `active`.
  - `ObligationProjection` (read-only entity) с геттерами всех полей + `key(): string` = `requirementId.'|'.itemId`.
  - `ObligationProjectionFilter` (readonly bag-of-fields): `Pager $pager, ?StringCollection $profileIds = null, ?string $requirementId = null, ?string $departmentId = null, ?ComplianceType $type = null, ?bool $active = null, ?\DateTimeImmutable $dueBefore = null`.
  - `ObligationProjectionRepositoryInterface`: `findByFilter(ObligationProjectionFilter): PaginationResult`; `findForProfiles(StringCollection $profileIds): array` (все строки списком людей, для дашборда); `upsertMany(iterable $rows): void`; `deleteByProfileNotIn(string $profileId, string $requirementId, array $keepItemIds): void` (orphan-чистка); `deleteForProfile(string $profileId): void`.

- [ ] **Step 1: Failing-тест репозитория**

```php
// ObligationProjectionRepositoryTest (functional, в контейнере)
public function test_upsert_then_find_by_filter_round_trips(): void
{
    $repo = self::getContainer()->get(ObligationProjectionRepositoryInterface::class);
    $repo->upsertMany([$this->rowArray(profileId: 'p1', requirementId: 'r1', itemId: 'i1', held: 2.0)]);

    $page = $repo->findByFilter(new ObligationProjectionFilter(Pager::fromPage(1, 10), profileIds: new StringCollection('p1')));

    self::assertCount(1, $page->items);
    self::assertSame('p1', $page->items[0]->profileId());
    self::assertSame(2.0, $page->items[0]->heldQuantity());
}

public function test_upsert_is_idempotent_on_profile_requirement_item(): void
{
    $repo = self::getContainer()->get(ObligationProjectionRepositoryInterface::class);
    $repo->upsertMany([$this->rowArray('p1','r1','i1', held: 2.0)]);
    $repo->upsertMany([$this->rowArray('p1','r1','i1', held: 5.0)]); // same key → update

    $page = $repo->findByFilter(new ObligationProjectionFilter(Pager::fromPage(1, 10), profileIds: new StringCollection('p1')));
    self::assertCount(1, $page->items);
    self::assertSame(5.0, $page->items[0]->heldQuantity());
}
```

- [ ] **Step 2: Прогнать — FAIL** (нет таблицы/классов). `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit --filter ObligationProjectionRepositoryTest`

- [ ] **Step 3: Миграция `Version20261101120000`** — `CREATE TABLE IF NOT EXISTS compliance_obligation_projection (…)` с полями/уником/индексами выше; `down()` = `DROP TABLE IF EXISTS`. Прогнать `bin/console doctrine:migrations:migrate -n` (и в test_db перед functional).

- [ ] **Step 4: `ObligationProjection` entity + `ObligationProjection.orm.xml`** (`read-only="true"`, таблица `compliance_obligation_projection`). Зарегистрировать маппинг-путь в `doctrine.yaml`, если read-model вне стандартного пути.

- [ ] **Step 5: `ObligationProjectionFilter` + интерфейс + `ObligationProjectionRepository`.** `findByFilter` — DQL на `ObligationProjection` c опциональными WHERE (profileIds IN, requirementId, departmentId, type, active, nextDueAt <= dueBefore) + `Pager::fromPage`. `upsertMany` — DBAL `INSERT … ON CONFLICT (profile_id, requirement_id, item_id) DO UPDATE SET …` (образец `CoatingSystemSearchCacheRepository::upsert`). Зарегистрировать как реализацию интерфейса в DI.

- [ ] **Step 6: Прогнать — PASS**, затем `./run check` зелёный.

- [ ] **Step 7: Commit** — `git add` файлов задачи; `git commit -m "Compliance D-проекция: новая таблица compliance_obligation_projection + read-only entity + findByFilter (expand)"`

---

## Task 2: Единый резолвер bucket над `ObligationView`

**Files:**
- Create: `app/src/Compliance/Domain/ValueObject/ObligationView.php`
- Modify: `app/src/Compliance/Domain/Service/ComplianceStatusResolver.php` (→ `ComplianceBucketResolver`)
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligation.php` (implements `ObligationView`)
- Modify: `app/src/Compliance/Infrastructure/Database/ReadModel/ObligationProjection.php` (implements `ObligationView`)
- Modify (call-sites): `ProfileComplianceDTOTransformer.php:56-63`, `PersonRowDTOTransformer.php:41`, `DueSoonScanner.php:62-69`
- Test: `app/tests/Unit/Compliance/Domain/Service/ComplianceBucketResolverTest.php`

**Interfaces:**
- Produces:
  - `interface ObligationView { public function isActive(): bool; public function lastFulfilledAt(): ?\DateTimeImmutable; public function nextDueAt(): ?\DateTimeImmutable; public function normQuantity(): ?float; public function heldQuantity(): float; }`
  - `ComplianceBucketResolver::bucketFor(ObligationView $o, \DateTimeImmutable $now): ComplianceBucket` и `statusFor(ObligationView $o, \DateTimeImmutable $now): ComplianceStatus` (тонкая обёртка `ComplianceStatus::fromBucket`). `DUE_SOON_DAYS = 60`. Старую 6-позиционную сигнатуру удалить.
- Consumes: `TrackedObligation` и `ObligationProjection` оба реализуют `ObligationView` (`normQuantity()` = amount поля `quantity`/norm; `heldQuantity()` = held).

- [ ] **Step 1: Failing-тест резолвера (включая анти-протухание)**

```php
public function test_overdue_when_next_due_in_past_without_any_event(): void
{
    $now = new \DateTimeImmutable('2026-10-17');
    $row = FakeObligationView::inventoried(active: true, nextDueAt: new \DateTimeImmutable('2026-10-01'), norm: 1.0, held: 1.0);
    self::assertSame(ComplianceBucket::OVERDUE, (new ComplianceBucketResolver())->bucketFor($row, $now));
}

public function test_due_soon_within_60_days_but_ok_beyond(): void
{
    $now = new \DateTimeImmutable('2026-10-17');
    $resolver = new ComplianceBucketResolver();
    $soon = FakeObligationView::inventoried(true, new \DateTimeImmutable('2026-12-10'), 1.0, 1.0); // +54д
    $ok   = FakeObligationView::inventoried(true, new \DateTimeImmutable('2027-02-01'), 1.0, 1.0); // +107д
    self::assertSame(ComplianceBucket::DUE_SOON, $resolver->bucketFor($soon, $now));
    self::assertSame(ComplianceBucket::OK, $resolver->bucketFor($ok, $now));
}

public function test_missing_when_held_below_norm(): void
{
    $now = new \DateTimeImmutable('2026-10-17');
    $row = FakeObligationView::inventoried(true, new \DateTimeImmutable('2027-01-01'), norm: 2.0, held: 1.0);
    self::assertSame(ComplianceBucket::MISSING, (new ComplianceBucketResolver())->bucketFor($row, $now)); // held<norm, эпсилон 1e-9
}
```
(`FakeObligationView` — тест-дубль, реализует `ObligationView`.)

- [ ] **Step 2: Прогнать — FAIL** (`vendor/bin/phpunit --filter ComplianceBucketResolverTest`).

- [ ] **Step 3: Ввести `ObligationView`; переписать резолвер** на `bucketFor(ObligationView,now)`, перенеся существующую ladder-логику 1:1 (пороги по `nextDueAt` vs `now`/`+60д`, held<norm с эпсилоном `1e-9` — КАНОНИЗИРОВАТЬ эпсилон здесь). Переименовать класс в `ComplianceBucketResolver` (сохранить `ComplianceStatus::fromBucket`). `TrackedObligation` и `ObligationProjection` — `implements ObligationView` (добавить недостающие геттеры-адаптеры).

- [ ] **Step 4: Репойнт 3 внешних call-site** (`ProfileComplianceDTOTransformer`, `PersonRowDTOTransformer`, `DueSoonScanner`) на `bucketFor($obligation, $now)`. (4-й — `ProfileCompliance::worstStatus` — удаляется в Task 5; пока оставить, адаптировав к новому интерфейсу или отметив для снятия.)

- [ ] **Step 5: Прогнать — PASS** (resolver-тест + существующие compliance-тесты), затем `./run check`.

- [ ] **Step 6: Commit** — `git commit -m "Compliance D-проекция: единый ComplianceBucketResolver.bucketFor(view,now) вместо 6-арг в 4 местах, эпсилон порога канонизирован"`

---

## Task 3: Проектор (параллельная запись новой таблицы) + гибрид-триггеры

**Files:**
- Create: `app/src/Compliance/Application/Service/ComplianceObligationProjector.php`
- Modify: `app/src/Compliance/Application/Service/ComplianceProjectionRebuilder.php` (делегирует проектору или поглощён)
- Modify: `RecordIssuanceCommandHandler.php`, `RecordInstructionCommandHandler.php` (если есть), `SignWriteOffActCommandHandler.php` (sync write-through)
- Modify: `RecomputeOnRequirementChangedHandler.php`, `RecomputeOnProfileSavedHandler.php`, `RecomputeOnWriteOffActSignedHandler.php`, `RecomputeProfileCommandHandler.php`, `RebuildComplianceProjectionCommand.php` (зовут проектор)
- Test: `app/tests/Functional/Compliance/Application/Service/ComplianceObligationProjectorTest.php`

**Interfaces:**
- Consumes: `ObligationProjectionRepositoryInterface` (Task 1), `ComplianceBucketResolver`/`ObligationDueCalculator`, `RequirementRepositoryInterface`, `ProfileComplianceRepositoryInterface`, `QueryBusInterface` (Personnel `GetProfileQuery` для `profileId`/`departmentId`).
- Produces: `ComplianceObligationProjector::projectProfile(string $profileId, ?string $requirementId = null): void` — пересобирает строки новой таблицы для профиля (опц. одного требования): норма (из `Requirement`) + персональные (пока из старых `origin=personal` строк агрегата) + журнал фактов → вычисляет `lastFulfilledAt`/`nextDueAt`(через `ObligationDueCalculator`)/`heldQuantity`/`active` → `upsertMany` + orphan-delete по ключам. `projectMany(?requirementIds, ?positionIds, ?profileIds)` — bulk, замена `ComplianceProjectionRebuilder::rebuild`.

- [ ] **Step 1: Failing-тест проектора (паритет + fan-out подписи)**

```php
// через EnrollsComplianceTrait: выдать карту, затем спроецировать
public function test_projection_row_matches_aggregate_after_issuance(): void
{
    $ctx = $this->enrollAndIssue(held: 2.0);               // создаёт профиль/требование/факт
    self::getContainer()->get(ComplianceObligationProjector::class)->projectProfile($ctx->profileId);

    $repo = self::getContainer()->get(ObligationProjectionRepositoryInterface::class);
    $rows = $repo->findForProfiles(new StringCollection($ctx->profileId));
    $row = $this->rowFor($rows, $ctx->obligationKey);
    self::assertSame(2.0, $row->heldQuantity());
    self::assertEquals($ctx->expectedNextDueAt, $row->nextDueAt());
    self::assertTrue($row->isActive());
}

public function test_sign_writeoff_updates_projection_for_all_people_in_one_tx(): void
{
    $a = $this->enrollAndIssue(profile: 'A', held: 2.0);
    $b = $this->enrollAndIssue(profile: 'B', held: 2.0);
    $this->signCrossPersonWriteOff([$a, $b], qtyEach: 1.0);   // команда SignWriteOffAct (sync write-through)

    $repo = self::getContainer()->get(ObligationProjectionRepositoryInterface::class);
    self::assertSame(1.0, $this->rowFor($repo->findForProfiles(new StringCollection($a->profileId)), $a->obligationKey)->heldQuantity());
    self::assertSame(1.0, $this->rowFor($repo->findForProfiles(new StringCollection($b->profileId)), $b->obligationKey)->heldQuantity());
}
```

- [ ] **Step 2: Прогнать — FAIL**.

- [ ] **Step 3: `ComplianceObligationProjector`** — перенести desired-vs-facts + orphan-cleanup из `ComplianceProjectionRebuilder` в проектор, записывая через `ObligationProjectionRepository::upsertMany`/orphan-delete в НОВУЮ таблицу. `ObligationDueCalculator` использовать как есть (в Task 7 переедет в Application-орбиту). `ComplianceProjectionRebuilder::rebuild` → делегирует `projector->projectMany(...)`.

- [ ] **Step 4: Sync write-through** — в `RecordIssuanceCommandHandler`/`RecordInstructionCommandHandler`/`SignWriteOffActCommandHandler` после мутации фактов вызвать `projector->projectProfile($profileId, $requirementId)` для затронутых профилей в той же транзакции (command.bus уже `doctrine_transaction`). Агрегат ПРОДОЛЖАЕТ вести старую таблицу (не трогаем `recomputeObligation`).

- [ ] **Step 5: Async-триггеры** — `RecomputeOn{RequirementChanged,ProfileSaved,WriteOffActSigned}Handler`, `RecomputeProfileCommandHandler`, `RebuildComplianceProjectionCommand` — репойнт на `projector` (остаются на своих транспортах).

- [ ] **Step 6: Прогнать — PASS**, `./run check`.

- [ ] **Step 7: Commit** — `git commit -m "Compliance D-проекция: ComplianceObligationProjector пишет новую таблицу (sync на факте, async на fan-out), параллельно старой"`

---

## Task 4: Флип читателей на новую read-модель

**Files:**
- Modify: `GetComplianceOverviewQueryHandler.php`, `GetPagedComplianceQueryHandler.php` (дашборд → `ObligationProjectionRepository`, не `findForDashboard`)
- Modify: `GetProfileComplianceQueryHandler.php` + `ProfileComplianceDTOTransformer.php` (карточка)
- Modify: `IssueAction.php` (чтение obligation-строк из проекции)
- Modify: `RequirementCardProjector.php` / `DownloadCardAction.php` (печать .docx)
- Modify: `PreviewAction.php` (через тот же query)
- Modify: `DueSoonScanner.php` + `ProfileComplianceRepository::findDueSoonObligations` → `ObligationProjectionRepository::findByFilter(dueBefore)`
- Test: расширить `GetComplianceOverviewQueryTest`, `GetPagedComplianceQueryTest`, `GetProfileComplianceQueryTest`, `DueSoonScannerTest`, `IssueActionTest` на чтение из проекции.

**Interfaces:**
- Consumes: `ObligationProjectionRepositoryInterface::findByFilter`/`findForProfiles` (Task 1), `ComplianceBucketResolver::bucketFor` (Task 2).
- Produces: читатели отдают прежние DTO, но собирают их из строк проекции; `ProfileCompliance` грузится ТОЛЬКО там, где нужен журнал фактов (запись/история/списание), не для obligation-строк.

- [ ] **Step 1: Failing/обновлённый тест дашборда** — читает проекцию без загрузки агрегата:

```php
public function test_dashboard_reads_projection_without_loading_aggregate(): void
{
    $ctx = $this->enrollAndIssueAndProject(held: 2.0, norm: 1.0); // проекция наполнена Task 3
    $res = $this->queryBus->execute(new GetComplianceOverviewQuery(/* фильтр */));
    self::assertSame(ComplianceBucket::OK->value, $this->bucketOf($res, $ctx->profileId, $ctx->obligationKey));
    // пин: источник — проекция (нет вызова ProfileComplianceRepository::findForDashboard)
}
```

- [ ] **Step 2: Прогнать — FAIL** (ещё читает агрегат).

- [ ] **Step 3: Репойнт дашборда** — `GetComplianceOverview`/`GetPagedCompliance` читают через `ObligationProjectionRepository`; bucket — `bucketFor($row,$now)`. Удалить/упростить `ProfileComplianceRepository::findForDashboard`.

- [ ] **Step 4: Репойнт карточки/`/issue`/печати/preview/сканера** — читать obligation-строки из проекции; `findDueSoonObligations` → `findByFilter(dueBefore: now+60д, active: true)`. `/issue` сохраняет прямой `findByProfile` ТОЛЬКО для журнала фактов/`heldOf`/`cardNumber` (эти остаются на агрегате до чистки Task 6).

- [ ] **Step 5: Прогнать — PASS**; греп: ни один читатель obligation-строк не зовёт `getObligations()`. `./run check`.

- [ ] **Step 6: Commit** — `git commit -m "Compliance D-проекция: дашборд/карточка/issue/печать/сканер читают новую read-модель без загрузки агрегата"`

---

## Task 5: Contract (домен) — `PersonalObligation` VO + снятие проекции с агрегата

**Files:**
- Create: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/PersonalObligation.php`
- Create: `app/src/Compliance/Infrastructure/Database/ORM/Aggregate/ProfileCompliance.PersonalObligation.orm.xml` (или embeddable-коллекция/json — по образцу хранения VO-списков в проекте)
- Modify: `ProfileCompliance.php` (снять `obligations`/`recompute*`/`worstStatus`/импорты read-сервисов; +`PersonalObligation[]`, `addPersonalObligation`/`pruneOrphanPersonalObligations` на VO)
- Modify: `ProfileCompliance.ProfileCompliance.orm.xml` (снять `one-to-many obligations`)
- Modify: `ComplianceObligationProjector.php` (персональные декларации — из VO агрегата, не из старых строк)
- Modify: `RecordIssuanceCommandHandler.php:87-89` (addPersonalObligation на VO)
- Migration: `Version20261101120500` — бэкфилл `PersonalObligation` из существующих `compliance_tracked_obligation WHERE origin='personal'` в новое хранилище деклараций (идемпотентно).
- Test: `app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/PersonalObligationTest.php` + расширить `ProfileComplianceTest`.

**Interfaces:**
- Produces: `final readonly class PersonalObligation { itemId, requirementId, label, ComplianceType type, Cadence cadence, ?Quantity quantity; }`. `ProfileCompliance::addPersonalObligation(string $itemId, string $requirementId, string $label, ComplianceType $type, Cadence $cadence, ?Quantity $quantity): string` (дедуп по lower(label) в требовании как сейчас); `personalObligations(): list<PersonalObligation>`; `pruneOrphanPersonalObligations(...)` оперирует VO.
- Consumes: проектор читает `profileCompliance->personalObligations()` + журнал.

- [ ] **Step 1: Failing round-trip-тест** — декларация переживает снятие старой коллекции:

```php
public function test_personal_obligation_declaration_survives_and_projects(): void
{
    $pc = $this->freshProfileCompliance();
    $pc->addPersonalObligation(itemId: 'pi1', requirementId: 'r1', label: 'Перчатки Х', type: ComplianceType::Material, cadence: Cadence::annual(), quantity: Quantity::of(1, Unit::piece()));
    $this->repository->add($pc);

    $reloaded = $this->repository->findByProfile($pc->profileId());
    self::assertCount(1, $reloaded->personalObligations());
    self::assertSame('Перчатки Х', $reloaded->personalObligations()[0]->label());
}
```

- [ ] **Step 2: Прогнать — FAIL**.

- [ ] **Step 3: `PersonalObligation` VO + хранение** (ORM; формат по аналогу VO-коллекций в проекте). `addPersonalObligation`/`pruneOrphanPersonalObligations` на VO-списке.

- [ ] **Step 4: Снять проекцию с агрегата** — удалить коллекцию `obligations`/`TrackedObligation`-маппинг, `recomputeObligation`/`recomputeAll`/`recomputeRequirement`/`putObligation`/`removeObligationByKey`/`worstStatus`, импорты `ComplianceStatusResolver`/`ObligationDueCalculator`. Агрегат перестаёт писать старую таблицу. Проектор читает персональные из VO.

- [ ] **Step 5: Миграция бэкфилла деклараций** `Version20261101120500` (idemпотентно) + прогнать.

- [ ] **Step 6: Прогнать — PASS** (`ProfileComplianceTest`, проектор-тест, функц. выдача), `./run check`.

- [ ] **Step 7: Commit** — `git commit -m "Compliance D-проекция: декларации off-norm в PersonalObligation VO, агрегат очищен от проекции/recompute/worstStatus/read-сервисов"`

---

## Task 6: Чистка `/issue` в view-builder + дедуп ФИО/формата

**Files:**
- Create: `app/src/Compliance/Application/UseCase/Query/GetIssuePage/GetIssuePageQuery.php` + `GetIssuePageQueryHandler.php` + `IssuePageDTO.php` (или `IssuePageViewBuilder`)
- Modify: `IssueAction.php` (тонкий: POST→команда, GET→query→render)
- Modify: дедуп ФИО — ввести один хелпер (напр. `ProfileDTO::fio()` в Personnel или Shared), заменить 7 копий (`PersonRowDTOTransformer:28`, `RequirementCardProjector:239`, `WriteOffActProjector:127`, `DueSoonScanner:107`, `IndexAction:127`, `WriteOffAct/ShowAction:262`, `EmitComplianceDueSoonCommand:54`)
- Modify: формат числа — только `AmountFormatter`; убрать инлайн-копию в `ProfileComplianceDTOTransformer:48`, свериться с `Quantity::label`
- Test: `app/tests/Functional/Compliance/Infrastructure/Controller/IssueActionTest.php` (расширить — страница рендерится из query), unit на ФИО-хелпер.

**Interfaces:**
- Consumes: `ObligationProjectionRepository`, `ComplianceBucketResolver`, `AmountFormatter`, ФИО-хелпер.
- Produces: `IssuePageDTO` (позиции с bucket/held/меткой/полями записи), собирается в Application, не в контроллере.

- [ ] **Step 1: Failing-тест** — `/issue` GET рендерит из query-пути (позиции отсортированы по срочности, bucket из резолвера):

```php
public function test_issue_page_renders_positions_sorted_by_urgency(): void
{
    $ctx = $this->enrollAndIssueAndProject(/* смесь просрочено/скоро/ок */);
    $client->request('GET', '/cabinet/compliance/issue/'.$ctx->profileId);
    self::assertResponseIsSuccessful();
    // порядок: просрочено/не выдано → скоро → в норме
    $this->assertOrder($client, ['Просрочено-позиция', 'Скоро-позиция', 'ОК-позиция']);
}
```

- [ ] **Step 2: Прогнать — FAIL/адаптировать**.

- [ ] **Step 3: Вынести сборку read-модели** из `IssueAction::renderForm` в `GetIssuePageQueryHandler`/view-builder (пороги — через резолвер, формат — через `AmountFormatter`, сортировка по bucket). Контроллер — тонкий.

- [ ] **Step 4: Дедуп ФИО (7×) и формата числа (3×)** — один хелпер/один `AmountFormatter`.

- [ ] **Step 5: Прогнать — PASS**; `cd app && yarn dev` если менялся Twig/JS; `./run check`.

- [ ] **Step 6: Commit** — `git commit -m "Compliance D-проекция: /issue read-модель переехала в query/view-builder, ФИО и формат числа схлопнуты в один источник"`

---

## Task 7: Финал — дроп старой таблицы + удаление мёртвого + ops

**Files:**
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20261101121000.php` (DROP `compliance_tracked_obligation`)
- Delete/Modify: `ComplianceProjectionRebuilder.php` (если полностью поглощён — удалить, иначе оставить тонкой обёрткой), `TrackedObligation.php` + его ORM (удалить), переместить `ObligationDueCalculator` из `Domain/Service` в Application-орбиту проектора.
- Modify: `RebuildComplianceProjectionCommand` (описание/ops), ops-раздел.
- Test: `app/tests/Functional/Compliance/Infrastructure/Database/DropTrackedObligationMigrationTest.php` (после дропа проекция-читатели живы).

**Interfaces:**
- Consumes: всё из Task 1-6 (новая таблица — единственный источник проекции).
- Produces: старая `compliance_tracked_obligation` удалена; `TrackedObligation` entity + маппинг удалены; `ObligationDueCalculator` вне `Domain/`.

- [ ] **Step 1: Failing/guard-тест** — после удаления `TrackedObligation` и дропа таблицы дашборд/карточка/сканер читают проекцию и зелены (нет ссылок на удалённый класс).

- [ ] **Step 2: Прогнать — FAIL** (ещё есть ссылки).

- [ ] **Step 3: Удалить `TrackedObligation` + ORM**, грепнуть все ссылки (0 остаться); `ObligationDueCalculator` → Application. `ComplianceProjectionRebuilder` — удалить или тонкая обёртка.

- [ ] **Step 4: Миграция `Version20261101121000`** — `DROP TABLE IF EXISTS compliance_tracked_obligation` (идемпотентно; `down()` воссоздаёт пустую для отката). Прогнать.

- [ ] **Step 5: Прогнать — PASS**, `./run check`. Грепом убедиться: 0 `getObligations()`/`TrackedObligation`/`ComplianceStatusResolver` в `Domain/` агрегата.

- [ ] **Step 6: Commit** — `git commit -m "Compliance D-проекция: старая таблица/сущность TrackedObligation снесены, ObligationDueCalculator вне домена (contract завершён)"`

---

## Deploy / ops (после мержа)

1. Migrate-first: `Version20261101120000` (новая таблица) + `Version20261101120500` (бэкфилл деклараций) в гейте ДО свопа. `Version20261101121000` (DROP старой) — применять ПОСЛЕ подтверждения паритета (можно отдельным поздним прогоном).
2. После свопа кода: `bin/console app:compliance:rebuild-projection` на проде — наполнить новую таблицу (≈124 строки).
3. Паритет-проверка перед дропом старой: число/значения строк новой vs старой совпадают (SQL-сверка `held`/`next_due_at`/`active` по ключу). Отчитаться юзеру.
4. Дневной сканер due-soon (06:00 МСК) — теперь читает новую таблицу; поведение не меняется.
5. Async-воркер (на проде падает в FATAL — поднимать) обслуживает только fan-out (норма/профиль); горячий путь факта не зависит от него.

## Self-review notes

- Покрытие §спеки: §4.1 таблица→T1; §4.2 проектор→T3/T7; §4.3 триггеры→T3; §4.4 резолвер→T2; §4.5 читатели→T4; §4.6 домен→T5; §4.7 /issue→T6; §5 миграции/ops→T1/T5/T7/ops; §2.1 bucket-на-чтении→T2 (анти-протухание тест). Пробелов нет.
- Типы согласованы: `ObligationView` (T2) реализуют `TrackedObligation` и `ObligationProjection`; `bucketFor(ObligationView,now)` зовётся в T4/T6; `projectProfile(profileId, ?requirementId)` из T3 зовётся в T3-триггерах; `findByFilter(ObligationProjectionFilter)` из T1 — в T4/T6.
- Review Focus: анти-протухание→T2 step1; паритет→T3 step1; персональные→T5 step1; забытый читатель→T4 step5 греп; гонка подписи→T3 step1.
