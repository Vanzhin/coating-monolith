# SurfaceTreatment CRUD Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Заменить VO `SurfacePreparation` в `CoatingSystem` на полноценный справочник `SurfaceTreatment` с CRUD, inline-модалкой и dropdown-выбором (никаких UUID руками).

**Architecture:** Новый агрегат-справочник `SurfaceTreatment` внутри `Coatings/` bounded context, FK из `CoatingSystem`, Restrict-delete, cabinet CRUD + JSON API для inline-create, Stimulus-модалка. Плюс исправление формы слоёв: `<input coatingId>` → `<select>` с coatings из БД.

**Tech Stack:** PHP 8.3, Symfony 7.0, Doctrine ORM XML mapping, PostgreSQL 17 (partial unique index + JSONB), PHPUnit 9.6, Twig, Stimulus.

**Spec:** `docs/plans/coating-system-surface-treatment/surface-treatment-design.md` — читать перед началом каждой задачи.

## Global Constraints

- Все инварианты в домене; `AppException` (HTTP 422) с русским сообщением при нарушении.
- Handlers implement `App\Shared\Application\Command\CommandHandlerInterface` / `QueryHandlerInterface`.
- Controllers per-action (по образцу `Coatings/Infrastructure/Controller/CoatingSystem/`).
- ORM XML в `Coatings/Infrastructure/Database/ORM/Aggregate/`.
- Миграции идемпотентные.
- Никаких эмодзи/dd/var_dump/закомментированного кода.
- Тесты зеркалят `src/`.
- Twig — только разметка (JS/CSS отдельно, Stimulus).
- SDD-режим: implementer коммитит per task. Сообщение коммита на английском.
- Веткa: **`feature/coating-system`** (продолжение PR #1, не отдельная).
- Ассеты пересобирать `docker run … node:22-alpine … yarn dev` после правок JS/Twig.
- Тесты гонять `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit ...`.

---

## File Structure

**Domain:**
- `Coatings/Domain/Aggregate/SurfaceTreatment/SurfaceTreatment.php`
- `Coatings/Domain/Repository/SurfaceTreatmentRepositoryInterface.php`
- `Coatings/Domain/Repository/SurfaceTreatmentsFilter.php`

**Infra:**
- `Coatings/Infrastructure/Database/ORM/Aggregate/SurfaceTreatment.SurfaceTreatment.orm.xml`
- `Coatings/Infrastructure/Database/DBAL/SubstrateScopeType.php`
- `Coatings/Infrastructure/Repository/SurfaceTreatmentRepository.php`
- `Coatings/Infrastructure/Controller/SurfaceTreatment/{Add,Update,List,Remove}Action.php`
- `Coatings/Infrastructure/Api/SurfaceTreatment/{Create,List}ApiAction.php`
- `Coatings/Infrastructure/Mapper/SurfaceTreatmentMapper.php`

**Application:**
- `Coatings/Application/DTO/SurfaceTreatments/{SurfaceTreatmentDTO,SurfaceTreatmentDTOTransformer}.php`
- `Coatings/Application/UseCase/Command/{CreateSurfaceTreatment,UpdateSurfaceTreatment,RemoveSurfaceTreatment}/{Command,CommandHandler,CommandResult}.php`
- `Coatings/Application/UseCase/Query/{FindSurfaceTreatmentById,ListSurfaceTreatments}/{Query,QueryHandler}.php`

**Twig / JS:**
- `Shared/Infrastructure/Templates/cabinet/coating/surface_treatment/{list,form}.html.twig`
- `Shared/Infrastructure/Templates/base.html.twig` — новый пункт меню.
- `Shared/Infrastructure/Templates/cabinet/coating/coating_system/form.html.twig` — переработка блока «Подготовка» + блока слоёв.
- `assets/controllers/surface_treatment_modal_controller.js`

**Migration:**
- `Shared/Infrastructure/Database/Migrations/Version20260728000000.php` — CREATE surface_treatment + FK в coating_system.

**Обновляемые файлы:**
- `Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php` — заменить свойство/сеттеры на treatment.
- `Coatings/Application/DTO/CoatingSystems/CoatingSystemDTO(+Transformer).php` — treatment вместо surfacePreparation.
- `Coatings/Application/UseCase/Command/{CreateCoatingSystem,UpdateCoatingSystemMetadata}` — принимают `surfaceTreatmentId`.
- `Coatings/Infrastructure/Mapper/CoatingSystemMapper.php` — новая shape.
- `Coatings/Infrastructure/Database/ORM/Aggregate/CoatingSystem.CoatingSystem.orm.xml` — убрать VO поля, добавить `many-to-one` на treatment.
- `Coatings/Domain/Repository/CoatingSystemRepositoryInterface.php` + repository — `countUsingSurfaceTreatment(uuid): int`.

**Удаляемые файлы:**
- `Coatings/Domain/Aggregate/CoatingSystem/SurfacePreparation.php`
- `Coatings/Infrastructure/Database/DBAL/SurfacePreparationType.php`
- `tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/SurfacePreparationTest.php`
- Соответствующая регистрация типа в `doctrine.yaml`.

---

## Task 1: `SurfaceTreatment` агрегат + инварианты (домен)

**Files:**
- Create: `app/src/Coatings/Domain/Aggregate/SurfaceTreatment/SurfaceTreatment.php`
- Test: `app/tests/Unit/Coatings/Domain/Aggregate/SurfaceTreatment/SurfaceTreatmentTest.php`

**Interfaces produced:**
- Конструктор `SurfaceTreatment(Uuid $id, string $description, ?string $code, ?string $standardCode, list<Substrate> $substrateScope)`. Extends `App\Shared\Domain\Aggregate\Aggregate`.
- Сеттеры `setDescription/setCode/setStandardCode/setSubstrateScope`, каждый touch()-ит `updatedAt`.
- Геттеры `getId/getDescription/getCode/getStandardCode/getSubstrateScope/getCreatedAt/getUpdatedAt`.
- Метод `supportsSubstrate(Substrate $s): bool` (используется CoatingSystem-инвариантом).

**Инварианты (сеттеры + конструктор):**
- description: не пустая (`trim() === ''`), ≤ 2000.
- code: nullable; если не null — не пустая, ≤ 30.
- standardCode: nullable; если не null — не пустая, ≤ 100.
- substrateScope: non-empty list; каждый элемент — `Substrate` enum; без дубликатов.

Все нарушения → `AppException` с русским сообщением.

- [ ] **Step 1: Написать проваливающиеся тесты** — покрыть construction happy-path + все edge-cases инвариантов (empty description, too long description, empty code, too long code, empty scope, duplicate substrate in scope, supportsSubstrate true/false).
- [ ] **Step 2: Прогнать тесты — FAIL (class not found).**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/SurfaceTreatment/SurfaceTreatmentTest.php --colors=never
```
- [ ] **Step 3: Реализовать `SurfaceTreatment` c инвариантами** — конструктор вызывает сеттеры, updatedAt = createdAt после setup.
- [ ] **Step 4: Прогнать — GREEN.**
- [ ] **Step 5: Commit** — `feat(coatings): add SurfaceTreatment aggregate with invariants`.

---

## Task 2: Repository + Filter + `SubstrateScopeType` DBAL + миграция

**Files:**
- Create: `app/src/Coatings/Domain/Repository/SurfaceTreatmentRepositoryInterface.php`
- Create: `app/src/Coatings/Domain/Repository/SurfaceTreatmentsFilter.php`
- Create: `app/src/Coatings/Infrastructure/Repository/SurfaceTreatmentRepository.php`
- Create: `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/SurfaceTreatment.SurfaceTreatment.orm.xml`
- Create: `app/src/Coatings/Infrastructure/Database/DBAL/SubstrateScopeType.php`
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20260728000000.php`
- Modify: `app/config/packages/doctrine.yaml` (register `substrate_scope` type).
- Modify: `app/config/services.yaml` (alias interface → impl).
- Test: `app/tests/Functional/Coatings/Infrastructure/Repository/SurfaceTreatmentRepositoryTest.php`

**Interfaces produced:**
- `SurfaceTreatmentRepositoryInterface::save/remove/findById/list/count`.
- `list(SurfaceTreatmentsFilter, int $limit, int $offset): list<SurfaceTreatment>` с фильтром по `?Substrate` (наличие в scope) и `?string $q` (LIKE по code+description).
- `count(SurfaceTreatmentsFilter): int`.
- `SubstrateScopeType` extends `JsonType`; `convertToPHPValue` → `array_map(Substrate::from, $decoded)`, `convertToDatabaseValue` → JSON `[$s->value, ...]`.

**Миграция:**
```sql
CREATE TABLE IF NOT EXISTS surface_treatment (
    id UUID PRIMARY KEY,
    description TEXT NOT NULL,
    code VARCHAR(30),
    standard_code VARCHAR(100),
    substrate_scope JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_surface_treatment_code_std
  ON surface_treatment (code, standard_code)
  WHERE code IS NOT NULL AND standard_code IS NOT NULL;
```

(Изменения `coating_system` — в Task 8, а не здесь.)

- [ ] **Step 1: Написать functional test round-trip.**
- [ ] **Step 2: Прогнать — FAIL (нет entity/repo/table).**
- [ ] **Step 3: Реализовать DBAL type, ORM XML, интерфейс, репозиторий, миграцию, регистрацию.**
- [ ] **Step 4: Прогнать миграцию + функциональный тест — GREEN.**
- [ ] **Step 5: Commit** — `feat(coatings): add SurfaceTreatment persistence layer`.

---

## Task 3: DTO + Transformer + Mapper

**Files:**
- Create: `app/src/Coatings/Application/DTO/SurfaceTreatments/SurfaceTreatmentDTO.php`
- Create: `app/src/Coatings/Application/DTO/SurfaceTreatments/SurfaceTreatmentDTOTransformer.php`
- Create: `app/src/Coatings/Infrastructure/Mapper/SurfaceTreatmentMapper.php`
- Test: `app/tests/Unit/Coatings/Application/DTO/SurfaceTreatments/SurfaceTreatmentDTOTransformerTest.php`
- Test: `app/tests/Unit/Coatings/Infrastructure/Mapper/SurfaceTreatmentMapperTest.php`

**Interfaces:**
- `SurfaceTreatmentDTO { string $id; string $description; ?string $code; ?string $standardCode; array $substrateScope; array $substrateScopeTitles; \DateTimeImmutable $createdAt; \DateTimeImmutable $updatedAt; }`
- `SurfaceTreatmentDTOTransformer::fromEntity(SurfaceTreatment): SurfaceTreatmentDTO`.
- `SurfaceTreatmentMapper::buildCommandFromInputData(array $input, ?string $id = null): CreateSurfaceTreatmentCommand|UpdateSurfaceTreatmentCommand`.
- `SurfaceTreatmentMapper::buildInputDataFromDto(?SurfaceTreatmentDTO): array`.
- `SurfaceTreatmentMapper::getValidationCollection(): Assert\Collection` — description NotBlank+Length, code Optional+Length, standardCode Optional+Length, substrateScope All(Choice enum values).

- [ ] **Step 1: Тесты для transformer (fromEntity) + mapper (round-trip).**
- [ ] **Step 2: RED.**
- [ ] **Step 3: Реализовать DTO/Transformer/Mapper.**
- [ ] **Step 4: GREEN.**
- [ ] **Step 5: Commit** — `feat(coatings): add SurfaceTreatment DTO/transformer/mapper`.

---

## Task 4: CRUD Commands + Handlers (Create/Update/Remove) + functional тесты

**Files:**
- Create: `app/src/Coatings/Application/UseCase/Command/CreateSurfaceTreatment/{Command,CommandHandler,CommandResult}.php`
- Create: `app/src/Coatings/Application/UseCase/Command/UpdateSurfaceTreatment/{Command,CommandHandler,CommandResult}.php`
- Create: `app/src/Coatings/Application/UseCase/Command/RemoveSurfaceTreatment/{Command,CommandHandler,CommandResult}.php`
- Modify: `Coatings/Domain/Repository/CoatingSystemRepositoryInterface.php` + `CoatingSystemRepository.php` — добавить `countUsingSurfaceTreatment(Uuid $treatmentId): int`.
- Test: `app/tests/Functional/Coatings/Application/UseCase/Command/{Create,Update,Remove}SurfaceTreatmentTest.php`

**Interfaces:**
- Commands extend `App\Shared\Application\Command\Command`, handlers implement `CommandHandlerInterface`.
- `RemoveSurfaceTreatmentCommandHandler`: если `countUsingSurfaceTreatment > 0` → AppException с русским сообщением.
- Handler'ы для Create/Update — обычные (findById → сеттеры → save).

- [ ] **Step 1: Functional тесты — happy path + not-found + remove-restrict (когда система ссылается).**
- [ ] **Step 2: RED.**
- [ ] **Step 3: Реализовать команды/хендлеры + countUsingSurfaceTreatment.**
- [ ] **Step 4: GREEN.**
- [ ] **Step 5: Commit** — `feat(coatings): add SurfaceTreatment create/update/remove commands with restrict-delete`.

---

## Task 5: Queries (FindById, ListWithSubstrateFilter)

**Files:**
- Create: `app/src/Coatings/Application/UseCase/Query/FindSurfaceTreatmentById/{Query,QueryHandler}.php`
- Create: `app/src/Coatings/Application/UseCase/Query/ListSurfaceTreatments/{Query,QueryHandler}.php`
- Test: `app/tests/Functional/Coatings/Application/UseCase/Query/{FindSurfaceTreatmentById,ListSurfaceTreatments}Test.php`

**Interfaces:**
- `ListSurfaceTreatmentsQuery(SurfaceTreatmentsFilter, int $page, int $perPage)` → `array{items: list<SurfaceTreatmentDTO>, total: int}`.
- Handlers implement `QueryHandlerInterface`.

- [ ] **Step 1: Functional tests: filter substrate, filter q, empty, pagination.**
- [ ] **Step 2: RED.**
- [ ] **Step 3: Реализовать.**
- [ ] **Step 4: GREEN.**
- [ ] **Step 5: Commit** — `feat(coatings): add SurfaceTreatment queries`.

---

## Task 6: Cabinet CRUD controllers + Twig

**Files:**
- Create: `app/src/Coatings/Infrastructure/Controller/SurfaceTreatment/{AddAction,UpdateAction,ListAction,RemoveAction}.php`
- Create: `app/src/Shared/Infrastructure/Templates/cabinet/coating/surface_treatment/{list,form}.html.twig`
- Modify: `app/src/Shared/Infrastructure/Templates/base.html.twig` — пункт меню «Подготовка поверхности».
- Test: `app/tests/Functional/Coatings/Infrastructure/Controller/SurfaceTreatment/{Add,Update,List,Remove}ActionTest.php`

**Routing:**
- Add     GET/POST `/cabinet/coating/surface-treatment/add`  → `app_cabinet_surface_treatment_add`.
- Update  GET/POST `/cabinet/coating/surface-treatment/{id}/update` (`requirements: id UUID regex`) → `..._update`.
- List    GET       `/cabinet/coating/surface-treatment/list` → `..._list`.
- Remove  POST      `/cabinet/coating/surface-treatment/{id}/remove` (regex) → `..._remove`.

**Права:** `Add/Update/List` — все аутентифицированные; `Remove` — `#[IsGranted('ROLE_ADMIN')]`.

- [ ] **Step 1: Functional тесты — 4 actions × happy path/not-found/access.**
- [ ] **Step 2: RED.**
- [ ] **Step 3: Реализовать controllers + templates + menu.**
- [ ] **Step 4: Прогнать тесты + `yarn dev`.**
- [ ] **Step 5: GREEN.**
- [ ] **Step 6: Commit** — `feat(coatings): add SurfaceTreatment cabinet CRUD`.

---

## Task 7: API endpoints (Create, List) + functional тесты

**Files:**
- Create: `app/src/Coatings/Infrastructure/Api/SurfaceTreatment/CreateApiAction.php`
- Create: `app/src/Coatings/Infrastructure/Api/SurfaceTreatment/ListApiAction.php`
- Modify: `app/config/routes.yaml` (`coatings_api` resource уже подключён — Task 16).
- Modify: `app/config/services.yaml` (controller.service_arguments для `Api/SurfaceTreatment/`).
- Test: `app/tests/Functional/Coatings/Infrastructure/Api/SurfaceTreatment/{Create,List}ApiActionTest.php`

**Endpoints:**
- `POST /api/surface-treatments` — JSON body → `CreateSurfaceTreatmentCommand` → return `{id, code, description, standardCode, substrateScope}` (201).
- `GET /api/surface-treatments?substrate=STEEL_CARBON&q=Sa` → `{items: [...]}`.

**Ошибки:** ValueError → 400; AppException пропускаем через `ResponseListener` → 422.

- [ ] **Step 1: Functional тесты — happy path, filter, invalid substrate → 400, missing description → 422.**
- [ ] **Step 2: RED.**
- [ ] **Step 3: Реализовать.**
- [ ] **Step 4: GREEN.**
- [ ] **Step 5: Commit** — `feat(coatings): add SurfaceTreatment API endpoints`.

---

## Task 8: Заменить `SurfacePreparation` VO на `SurfaceTreatment` FK в `CoatingSystem`

**Files:**
- Modify: `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php` — новое поле, инвариант substrate ∈ scope.
- Modify: `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/CoatingSystem.CoatingSystem.orm.xml` — убрать `surface_preparation`, добавить `many-to-one` на SurfaceTreatment (`on-delete="RESTRICT"`, `fetch="EAGER"`).
- Modify: `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTO.php` — новые поля (surfaceTreatmentId, surfaceTreatmentCode, surfaceTreatmentDescription, ...).
- Modify: `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTOTransformer.php` — маппит treatment.
- Modify: `app/src/Coatings/Application/UseCase/Command/CreateCoatingSystem/{Command,CommandHandler}.php` — принимает `surfaceTreatmentId`, handler грузит FK + вызывает setSurfaceTreatment.
- Modify: `app/src/Coatings/Application/UseCase/Command/UpdateCoatingSystemMetadata/{Command,CommandHandler}.php` — аналогично.
- Modify: `app/src/Coatings/Infrastructure/Mapper/CoatingSystemMapper.php` — новая shape (без grade/standard/description по подготовке).
- Modify: `app/src/Shared/Infrastructure/Database/Migrations/Version20260728000000.php` — добавить в up(): DROP колонки surface_preparation_*, ADD surface_treatment_id UUID NOT NULL FK RESTRICT.
- Delete: `app/src/Coatings/Domain/Aggregate/CoatingSystem/SurfacePreparation.php`
- Delete: `app/src/Coatings/Infrastructure/Database/DBAL/SurfacePreparationType.php`
- Delete: `app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/SurfacePreparationTest.php`
- Modify: `app/config/packages/doctrine.yaml` — убрать регистрацию `surface_preparation` type.
- Modify: `app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemTest.php` — обновить фабрики.
- Modify: тесты Task 7/8/11/12/15/16 — прокидывать treatment.

**Инвариант в CoatingSystem::setSurfaceTreatment(SurfaceTreatment $t) / setSubstrate(Substrate $s):**
```
if (!$treatment->supportsSubstrate($this->substrate)) {
    throw new AppException(sprintf(
        'Подготовка «%s» применима к [%s], а в системе выбрана %s.',
        $treatment->getCode() ?? $treatment->getDescription(),
        implode(', ', array_map(fn($s) => $s->title(), $treatment->getSubstrateScope())),
        $this->substrate->title(),
    ));
}
```

- [ ] **Step 1: Обновить unit tests CoatingSystem** — добавить treatment в фабрику, тест на инвариант substrate ∉ scope.
- [ ] **Step 2: RED (compile failure на несуществующем поле).**
- [ ] **Step 3: Реализовать замену VO → FK, обновить ORM XML, миграцию, DTO/mapper/handlers.**
- [ ] **Step 4: Удалить старый VO/тест/DBAL, снять регистрацию.**
- [ ] **Step 5: Прогнать миграцию с нуля + все unit/functional тесты Coatings.**
- [ ] **Step 6: GREEN.**
- [ ] **Step 7: Commit** — `refactor(coatings): replace SurfacePreparation VO with SurfaceTreatment FK`.

---

## Task 9: Форма CoatingSystem — dropdown treatment + модалка inline-create + dropdown coating (не UUID)

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Controller/CoatingSystem/AddAction.php` — прокинуть в шаблон `treatments` (по substrate) + полный список `coatings` (для select слоёв).
- Modify: `app/src/Coatings/Infrastructure/Controller/CoatingSystem/UpdateAction.php` — то же.
- Modify: `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/form.html.twig`:
  - Блок «Подготовка поверхности» — один `<select name="surfaceTreatmentId">` + кнопка «+ Создать подготовку» (data-action к Stimulus).
  - Блок «Слои системы»: `<input name="layers[N][coatingId]">` → `<select>` с option-ами из `coatings` (title + base + DFT range).
  - `<template>` для нового слоя также содержит `<select>` (клонируется Stimulus).
- Create: `app/assets/controllers/surface_treatment_modal_controller.js` — targets modal/description/code/standardCode/scopeSelect/submitButton/errorBox; action `open`, `submit`, `close`.
- Modify: form.html.twig — добавить Bootstrap modal-разметку для inline-create.
- Modify: `app/src/Coatings/Application/UseCase/Query/ListCoatingSystems/*.php`? — нет, нужен просто вспомогательный список coatings для формы. Использовать существующий `GetPagedCoatingsQuery` (был в Task 15).
- Test: обновить `AddActionTest` / `UpdateActionTest` — теперь передавать `surfaceTreatmentId` (uuid из фикстуры) и `layers[N][coatingId]` (uuid Coating).

**Modal flow:**
1. Клик «+ Создать» → Stimulus open modal (Bootstrap modal), preselect substrateScope с текущим system.substrate.
2. Submit → fetch POST /api/surface-treatments → success: добавить `<option>` в select, `option.selected = true`, `modal.hide()`. Ошибка → показать errorBox.

- [ ] **Step 1: Обновить functional тесты (Task 15 переделаны под treatment/coatingId FK).**
- [ ] **Step 2: RED.**
- [ ] **Step 3: Реализовать form.html.twig изменения + Stimulus + Controller extension.**
- [ ] **Step 4: `yarn dev`.**
- [ ] **Step 5: GREEN.**
- [ ] **Step 6: Commit** — `feat(coatings): dropdown treatment/coating in CoatingSystem form with inline-create modal`.

---

## Task 10: Full-suite check + PHPStan + cs-fixer + smoke

- [ ] **Step 1: `phpunit tests/Unit/Coatings tests/Functional/Coatings` — все зелёные (211 + новые).**
- [ ] **Step 2: PHPStan 0 errors в Coatings.**
- [ ] **Step 3: `php-cs-fixer` dry-run; если diff — apply + commit `chore(coatings): apply cs-fixer to SurfaceTreatment code`.**
- [ ] **Step 4: Миграция с нуля.**
- [ ] **Step 5: Rebuild compliance.**
- [ ] **Step 6: Manual smoke checklist** (для человека, не implementer):
  - Создать SurfaceTreatment «Sa 2 / ГОСТ Р ИСО 8501-1 / STEEL_CARBON» через CRUD.
  - Создать систему: выбрать substrate STEEL_CARBON → в dropdown treatment появляется «Sa 2».
  - Нажать «+ Создать подготовку» → создать «Обмыв водой» без code/standard → сохранить → появляется в dropdown, выбирается.
  - Слои — выбор coating из dropdown.
  - Попытаться удалить treatment «Sa 2» когда система на него ссылается → 422 «Нельзя удалить: используется в системах».

---

## Self-Review

**Spec coverage:** Все разделы design.md покрыты Tasks 1–10 (домен → persistence → DTO/mapper → commands → queries → cabinet UI → API → перезаливка в CoatingSystem → форма → финал).

**Placeholder scan:** Нет TBD/TODO/«implement later». Каждая задача содержит конкретные пути файлов, интерфейсы, шаги TDD, точные команды.

**Type consistency:**
- `SurfaceTreatment::supportsSubstrate(Substrate)` (Task 1) — используется в `CoatingSystem::setSurfaceTreatment` (Task 8).
- `CoatingSystemRepositoryInterface::countUsingSurfaceTreatment` (Task 4) — используется в `RemoveSurfaceTreatmentCommandHandler` (Task 4, тот же таск).
- `SurfaceTreatmentDTO` (Task 3) — используется в query handlers (Task 5) и API list action (Task 7).
- `SubstrateScopeType` DBAL (Task 2) — регистрируется в doctrine.yaml, используется в ORM XML (Task 2).

**Scope check:** Хорошо декомпозировано на 10 самостоятельных тестуемых задач. Task 8 (перезаливка) — самая тяжёлая, но неизбежная integrative.

**Ambiguity check:**
- «Права на inline-create через API» — Task 7 default: любой аутентифицированный (симметрично cabinet Add).
- «Substrate → treatment filter» — Task 2 repository делает JSONB contains query. Postgres синтаксис уточним в реализации.
- «`fetch=EAGER` на treatment» — Task 8, симметрично coating в CoatingSystemLayer (см. Task 12 базы), избежит lazy-proxy проблем с `readonly $id`.

Готов к запуску SDD.
