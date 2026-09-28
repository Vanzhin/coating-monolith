# Деплой 1: домен `Personnel` — профиль сотрудника, справочник должностей, дерево отделов компании

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development или superpowers:executing-plans, реализовать по задачам. Шаги — чекбоксы `- [ ]`.
>
> Соседи: `compliance-siz-2-requirements.md`, `-3-tracking-card.md`, `-4-dashboard-alerts.md`. Спека: `docs/plans/compliance-siz-design.md`. Этот файл самодостаточен.

**Цель:** новый bounded-context `Personnel` — сотрудник как отдельная доменная сущность (`Profile`, 0..1 на `User`), справочник должностей (`Position`, quick-create как контрагенты), иерархический справочник отделов компании (`Department`: дерево, привязка к `Counterparty`, начальник на узле). Вкладка «Персонал».

**Архитектура:** DDD+Hexagonal, три слоя как у остальных контекстов. `Profile` ссылается на `Position`/`Counterparty`/`Department` снапшотом `Reference {id,title}` (заморозка подписи, паттерн из Reports). Инварианты — в домене (VO/агрегат), кидают `AppException` (RU-сообщение). `Position` — зеркало `Counterparty` без ИНН. `Department` — реляционное дерево (self-parent). Организация-работодатель = существующий `Counterparty` (в контексте Reports), тянем через query-шину.

**Tech Stack:** PHP/Symfony 8, Doctrine ORM (XML mapping), Doctrine DBAL custom JSON types, Symfony Messenger (command/query bus по marker-interface), Stimulus (`async_typeahead_controller.js`), Twig, PostgreSQL.

## Global Constraints

- VO — `final readonly`, кидает `App\Shared\Infrastructure\Exception\AppException` (не `InvalidArgumentException`), сообщения RU для пользователя.
- Бизнес-правила только в домене (VO/агрегат/спека), НЕ в контроллере/команде/маппере.
- Хендлеры регистрируются через `implements CommandHandlerInterface`/`QueryHandlerInterface` (auto-tag `_instanceof` в `services.yaml`), НЕ `#[AsMessageHandler]`. Иначе NoHandler в runtime.
- id генерится в Maker/handler (`UuidService::generateUuid()`), передаётся в конструктор агрегата параметром (как `Coating`/`Report`, не как `Tag`).
- Контроллеры тонкие, per-action (копировать раскладку `Reports/.../Controller/Counterparty/`, НЕ моно-контроллеры). Авторизация — `PersonnelAccessControl` в Application, `#[IsGranted]` НЕ используем.
- Списки строковых id (если появятся в фильтрах/DTO) — `StringCollection`, не `array`.
- Тесты зеркалят `src/`: `tests/Unit/Personnel/...`, `tests/Functional/Personnel/...`. VO — юнит с граничными случаями (кидающие `AppException` — отдельным тест-методом). Хендлеры — функциональные с реальной БД (не моки), трейт `App\Tests\Support\AuthenticatesActorTrait::authenticateAsSystem()`.
- Фронт (Twig/JS/CSS) PHP-тестами не проверяется — верификация сборкой (`cd app && yarn dev`) + браузер.
- Стили не сочинять — копировать разметку ближайшего аналога (`admin/reports/counterparty/`, `cabinet/*`), без новых классов/цветов.
- Гейты гонять в контейнере (`./run check`), тест-БД мигрировать. Коммиты — по задаче (SDD-исключение: имплементер коммитит на задачу), пуш/мерж — по апруву пользователя. Закладываем cleanup style/phpstan.
- Миграции идемпотентные (`IF NOT EXISTS`), `src/Shared/Infrastructure/Database/Migrations/Version<ts>.php` (эталон DDL — соседние `Version*`).

## Review Focus

- **Отдел из чужой компании как parent/у профиля** — `Department.parentId` другой компании ИЛИ `Profile.departmentId` не принадлежит `Profile.organizationId` → должно падать `AppException`, не молча сохраняться (тест в T5/T8).
- **Цикл в дереве отделов** — назначение узлу родителя-потомка → `AppException` (тест в T5).
- **Второй профиль на того же `User`** → `AppException` про уникальность (тест в T8).
- **Пустое/невалидное ФИО** (пустая фамилия/имя) → `AppException` (тест в T6).
- **Удаление должности/отдела, на который ссылается профиль** — не осиротить ссылку: запрет удаления при использовании ИЛИ понятная ошибка (тест в T3/T5). Снапшот `{id,title}` на профиле выживает (подпись заморожена), но id повиснет — решаем запретом удаления используемого.

---

## T1. VO `FullName` и `Sizes`

**Файлы (новые):**
- `app/src/Personnel/Domain/Aggregate/Profile/FullName.php`
- `app/src/Personnel/Domain/Aggregate/Profile/Sizes.php`

`FullName` — `final readonly class FullName implements \JsonSerializable`: `__construct(string $lastName, string $firstName, ?string $middleName = null)`. `trim`; фамилия и имя непусты (иначе `AppException('Фамилия и имя обязательны.')`); `middleName` — null-if-empty. `fullString(): string` («Фамилия Имя Отчество»), `short(): string` («Фамилия И. О.»), `static fromArray(array): self`, `jsonSerialize(): array {lastName,firstName,middleName}`.

`Sizes` — `final readonly class Sizes implements \JsonSerializable`, все поля опциональны: `clothing`, `shoes`, `headgear`, `gasMask`, `respirator`, `gloves` (строки — размеры бывают «52-54», «10.5»), `height` (?string), `gender` (`?Gender` — маленький enum `Male/Female`). `static empty(): self`, `fromArray`, `jsonSerialize`. Без инвариантов (значения свободные), но пустые строки → null.

- [ ] Юнит `tests/Unit/Personnel/Domain/Aggregate/Profile/FullNameTest.php`: полное ФИО, без отчества, пустая фамилия → AppException, пустое имя → AppException, `short()`/`fullString()` формат, round-trip `fromArray(jsonSerialize())`.
- [ ] Юнит `SizesTest.php`: round-trip, пустые строки → null, `empty()`.
- [ ] Коммит.

## T2. VO `Reference` для Personnel (снапшот ссылок) + DBAL-тип

**Решение:** не тащим `Reports\...\Reference`, повторяем паттерн в своём контексте (границы контекста).

**Файлы (новые):**
- `app/src/Personnel/Domain/ValueObject/Reference.php` — `final readonly class Reference implements \JsonSerializable { public function __construct(public string $id, public string $title) }` + `static fromArray(array): self` + `jsonSerialize(): array`. (Копия семантики `Reports\Domain\Aggregate\Report\Reference`.)
- `app/src/Personnel/Infrastructure/Database/DBAL/ReferenceType.php` — extends `App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType`; `valueClass()`/`hydrate()` → `Reference::fromArray`. Имя типа `personnel_reference`.
- `app/src/Personnel/Infrastructure/Database/DBAL/FullNameType.php` (тип `personnel_full_name`), `SizesType.php` (тип `personnel_sizes`) — по тому же паттерну.

**Регистрация:** `app/config/packages/doctrine.yaml` → `dbal.types`: `personnel_reference`, `personnel_full_name`, `personnel_sizes` → соответствующие классы.

- [ ] Юнит round-trip `ReferenceType`/`FullNameType`/`SizesType` (`convertToPHPValue(convertToDatabaseValue(...))`) — если в проекте есть аналогичные DBAL-тесты, зеркалить; иначе покрыть через функц. тест агрегата (T8).
- [ ] Коммит.

## T3. Агрегат `Position` (справочник должностей) + спека уникальности + репозиторий

**Файлы (новые):**
- `app/src/Personnel/Domain/Aggregate/Position/Position.php` — `class Position extends App\Shared\Domain\Aggregate\Aggregate`. `__construct(Uuid $id, string $title, PositionSpecification $specification)`: `setTitle` (trim, непусто, max 150 → `AppException`; затем `$specification->uniqueTitle->satisfy($this)`). `getId(): string`, `getTitle(): string`, `rename(string $title): void`.
- `app/src/Personnel/Domain/Aggregate/Position/Specification/PositionSpecification.php` — группа спек (конструктор с `UniqueTitlePositionSpecification`). Зеркало `Reports\...\Counterparty\Specification\CounterpartySpecification`.
- `app/src/Personnel/Domain/Aggregate/Position/Specification/UniqueTitlePositionSpecification.php` — `satisfy(Position)`: если `($e=$repo->findOneByTitle($title))!==null && $e->getId()!==$position->getId()` → `AppException('Должность «…» уже существует.')`.
- `app/src/Personnel/Domain/Repository/PositionRepositoryInterface.php` — `add`, `remove`, `findOneById(string): ?Position`, `findOneByTitle(string): ?Position`, `findByIds(StringCollection): array`, `suggest(string $query, int $limit): array`, `countProfilesUsing(string $positionId): int` (для запрета удаления — реализуется через `Profile`-репозиторий или отдельный query; см. ниже).
- `app/src/Personnel/Infrastructure/Repository/PositionRepository.php` — `ServiceEntityRepository<Position> implements PositionRepositoryInterface` (зеркало `CounterpartyRepository`). `suggest` — `LOWER(p.title) LIKE LOWER(:q)`, order title ASC, limit.
- `app/src/Personnel/Infrastructure/Database/ORM/Aggregate/Position.Position.orm.xml` — таблица `personnel_position`, `id` uuid strategy NONE, `title` unique.

**Регистрация:** `doctrine.yaml` orm.mappings блок `Personnel` (dir `src/Personnel/Infrastructure/Database/ORM/Aggregate`, prefix `App\Personnel\Domain\Aggregate`, alias `Personnel`); `services.yaml` алиас `PositionRepositoryInterface → PositionRepository`.

**Запрет удаления используемой должности:** проверку «есть профили с этой должностью» делаем в `DeletePositionCommandHandler` через `ProfileRepositoryInterface::countByPositionId` (T7) → если >0, `AppException('Должность используется в профилях, удаление запрещено.')`.

- [ ] Миграция `Version<ts>.php`: `CREATE TABLE IF NOT EXISTS personnel_position (id VARCHAR(36) PRIMARY KEY, title VARCHAR(150) NOT NULL); CREATE UNIQUE INDEX IF NOT EXISTS uniq_personnel_position_title ON personnel_position (title);`
- [ ] Функц. тест `tests/Functional/Personnel/...`: create должности, дубль title → AppException, suggest находит по префиксу.
- [ ] Коммит.

## T4. `Position` — команды/хендлеры + per-action контроллеры (quick-create) + typeahead

**Файлы (новые), зеркало `Reports/.../Controller/Counterparty/` и его Command/Query:**
- Command: `Create/Update/DeletePositionCommand(+Handler+Result)` в `app/src/Personnel/Application/UseCase/Command/...`. Хендлеры `implements CommandHandlerInterface`, авторизация через `PersonnelAccessControl::canManage()` (T10) иначе `ForbiddenException`.
- Query: `SuggestPositions`, `GetPositionsByIds` (QueryHandler → DTO `PositionDTO {id,title}`).
- Контроллеры `app/src/Personnel/Infrastructure/Controller/Position/{AddAction,UpdateAction,DeleteAction,ListAction,SuggestAction,QuickCreateAction,ByIdsAction}.php` — тонкие, копия раскладки Counterparty. `QuickCreateAction` — POST JSON `{title}` → `CreatePositionCommand` → 201 `{id,title}`; 422 с RU-сообщением на дубль.
- Роут-блок в `app/config/routes.yaml`: `personnel: { resource: ../src/Personnel/Infrastructure/Controller, type: attribute }`.
- Шаблоны админ-списка/формы должностей — копия `admin/reports/counterparty/{index,form}.html.twig` в `admin/personnel/position/` (только тексты/поля меняем).

**Typeahead:** переиспользуем существующий `app/assets/controllers/async_typeahead_controller.js` (для полей выбора должности в форме профиля, T9) — endpoints `SuggestAction`/`ByIdsAction`. Новый JS не пишем.

- [ ] Функц. тесты хендлеров (create/update/delete, delete используемой → AppException после T7), suggest.
- [ ] `cd app && yarn dev`; браузер: создать должность quick-create, дубль → сообщение.
- [ ] Коммит.

## T5. Агрегат `Department` (иерархия) + репозиторий (обход дерева) + ORM/миграция

**Файлы (новые):**
- `app/src/Personnel/Domain/Aggregate/Department/Department.php` — `class Department extends Aggregate`. Поля: `Uuid $id`, `string $title`, `string $companyId` (Counterparty), `?string $parentId`, `?string $headUserUlid`. Конструктор `__construct(Uuid $id, string $title, string $companyId, ?string $parentId, ?string $headUserUlid, DepartmentTreePolicy $policy)`. Инварианты через `DepartmentTreePolicy` (доменный сервис, T5b): parent принадлежит той же компании; назначение parent не создаёт цикл. Методы `rename`, `moveTo(?string $parentId)`, `assignHead(?string $userUlid)`, `getters`.
- `app/src/Personnel/Domain/Service/DepartmentTreePolicy.php` — доменный сервис: `assertParentValid(Department $node, ?string $parentId)` — грузит parent через `DepartmentRepositoryInterface`, проверяет `parent.companyId === node.companyId` (иначе `AppException('Родительский отдел из другой компании.')`) и что `parentId` не в поддереве `node` (обход вниз → `AppException('Нельзя сделать отдел подчинённым своему потомку (цикл).')`).
- `app/src/Personnel/Domain/Repository/DepartmentRepositoryInterface.php` — `add`, `remove`, `findOneById`, `findByCompany(string $companyId): array`, `findChildren(string $parentId): array`, `findAncestors(string $departmentId): array` (узел→корень, для резолва начальника в Д4), `findByIds`, `countProfilesUsing(string): int` (через Profile-репо в handler).
- `app/src/Personnel/Infrastructure/Repository/DepartmentRepository.php` — реализация; `findAncestors` — итеративный подъём по `parentId` (компаний немного, глубина мала) ИЛИ recursive CTE (`WITH RECURSIVE`), выбрать recursive CTE для одного запроса.
- `app/src/Personnel/Infrastructure/Database/ORM/Aggregate/Department.Department.orm.xml` — таблица `personnel_department`, индексы по `company_id`, `parent_id`.

- [ ] Миграция: `CREATE TABLE IF NOT EXISTS personnel_department (id VARCHAR(36) PRIMARY KEY, title VARCHAR(150) NOT NULL, company_id VARCHAR(36) NOT NULL, parent_id VARCHAR(36) NULL, head_user_ulid VARCHAR(26) NULL); CREATE INDEX IF NOT EXISTS idx_personnel_department_company ON personnel_department (company_id); CREATE INDEX IF NOT EXISTS idx_personnel_department_parent ON personnel_department (parent_id);`
- [ ] Юнит `DepartmentTreePolicyTest` (мок/стаб репо или функц.): parent чужой компании → AppException; parent-потомок (цикл) → AppException; валидный parent → ок.
- [ ] Функц. тест `DepartmentRepository::findAncestors` возвращает цепочку до корня.
- [ ] Коммит.

## T6. `Department` — команды/хендлеры + CRUD-контроллеры + UI дерева

**Файлы (новые):**
- Command `Create/Update/Move/Delete/AssignHeadDepartmentCommand(+Handler+Result)`; авторизация `canManage`. Delete используемого (есть профили/дети) → `AppException`.
- Query `GetCompanyDepartmentTree(companyId): TreeDTO` (вложенный DTO дерева, без array-shape), `SuggestDepartments`/`GetDepartmentsByIds`.
- Контроллеры `Infrastructure/Controller/Department/{ListAction,AddAction,UpdateAction,MoveAction,DeleteAction,AssignHeadAction,ByIdsAction}.php`.
- Начальник узла — выбор `User` (по ULID). Нужен typeahead по пользователям: если в проекте уже есть suggest пользователей (проверить `Users/.../Controller`), переиспользовать; иначе добавить `Users` `SuggestUsersAction` (по email/ФИО-профиля) — минимально по email. Компания узла — Counterparty typeahead (cross-context, Reports `SuggestCounterpartiesAction`).
- Шаблон дерева отделов — простой вложенный список (копировать стиль существующих list-страниц cabinet/admin, без новых классов); orgchart-визуал не делаем.

- [ ] Функц. тесты: create корневого/дочернего, move (валидный/цикл), assign head, delete используемого → AppException.
- [ ] `yarn dev` + браузер: построить дерево из 2 уровней, назначить начальника.
- [ ] Коммит.

## T7. Агрегат `Profile` + спека уникальности + репозиторий + ORM/DBAL/миграция

**Файлы (новые):**
- `app/src/Personnel/Domain/Aggregate/Profile/Profile.php` — `class Profile extends Aggregate`. Поля: `Uuid $id`, `string $userUlid`, `FullName $fullName`, `Reference $position`, `Reference $organization`, `Reference $department`, `?string $personnelNumber`, `?\DateTimeImmutable $hiredAt`, `Sizes $sizes`, `createdAt/updatedAt`, `int $version`. Конструктор берёт id из вне. Сеттеры с инвариантами. **Инвариант связки:** метод `assertDepartmentBelongsToOrganization()` — при установке department/organization проверять, что `department` относится к `organization` (проверка на уровне handler'а через `DepartmentRepository::findOneById`, т.к. агрегат не ходит в репо; см. ниже). Спека уникальности `UniqueUserProfileSpecification`: один профиль на `userUlid`.
- `app/src/Personnel/Domain/Aggregate/Profile/Specification/{ProfileSpecification,UniqueUserProfileSpecification}.php`.
- `app/src/Personnel/Domain/Repository/ProfileRepositoryInterface.php` — `add`, `remove`, `findOneById`, `findOneByUserUlid(string): ?Profile`, `findByFilter(...)` (для списка), `countByPositionId(string): int`, `countByDepartmentId(string): int`.
- `app/src/Personnel/Infrastructure/Repository/ProfileRepository.php`.
- ORM `Profile.Profile.orm.xml` — таблица `personnel_profile`; `user_ulid` VARCHAR(26) unique; `full_name` type `personnel_full_name`; `position`/`organization`/`department` type `personnel_reference`; `sizes` type `personnel_sizes`; `personnel_number` nullable; `hired_at` datetime nullable; `version` version="true".

**Инвариант «department принадлежит organization»** живёт в `CreateProfileCommandHandler`/`UpdateProfileCommandHandler`: резолвим `Department` по id, если `dept.companyId !== command.organizationId` → `AppException('Отдел не принадлежит выбранной организации.')`. (Правило про связь двух ссылок, требующее данных из репозитория — в Application-хендлере, не в конструкторе VO; это допустимо, т.к. нужен фетч.)

- [ ] Миграция: `CREATE TABLE IF NOT EXISTS personnel_profile (id VARCHAR(36) PRIMARY KEY, user_ulid VARCHAR(26) NOT NULL, full_name JSONB NOT NULL, position JSONB NOT NULL, organization JSONB NOT NULL, department JSONB NOT NULL, personnel_number VARCHAR(50) NULL, hired_at TIMESTAMP(0) NULL, sizes JSONB NOT NULL, created_at TIMESTAMP(0) NOT NULL, updated_at TIMESTAMP(0) NOT NULL, version INT NOT NULL DEFAULT 1); CREATE UNIQUE INDEX IF NOT EXISTS uniq_personnel_profile_user ON personnel_profile (user_ulid);`
- [ ] Функц. тест: create профиля (реальная БД); второй профиль на тот же userUlid → AppException; department чужой организации → AppException.
- [ ] Коммит.

## T8. `Profile` — Maker, команды/хендлеры, DTO/Transformer

**Файлы (новые):**
- `app/src/Personnel/Domain/Factory/ProfileMaker.php` — собирает `Profile` (генерит `Uuid`, строит `FullName`/`Sizes`, резолвит снапшоты `Reference` из id: `Position` (свой репо), `Department` (свой репо), `Organization`/Counterparty (через query-шину `GetCounterpartiesByIdsQuery`/аналог Reports — проверить имя; см. §5 спеки)). Проверяет связку dept↔org.
- Command `CreateProfileCommand(userUlid, lastName, firstName, middleName, positionId, organizationId, departmentId, personnelNumber, hiredAt, sizes...)` + Handler (авторизация `canManage`), `UpdateProfileCommand` + Handler, `DeleteProfileCommand` + Handler.
- DTO `app/src/Personnel/Application/DTO/Profile/ProfileDTO.php` (плоский: id, userUlid, ФИО-строки, positionId/Title, organizationId/Title, departmentId/Title, personnelNumber, hiredAt, размеры) + `ProfileDTOTransformer::fromEntity`.
- Query `GetProfile`, `GetPagedProfiles(filter)`, `GetProfileByUserUlid`.

**Кросс-контекст (organization=Counterparty):** резолв title и suggest организации — через опубликованные query Reports (`SuggestCounterparties`/`GetCounterpartiesByIds`). НЕ лезть в репозиторий Reports напрямую. Если нужного query нет — добавить его в Reports минимально (отдельная микро-задача, отметить).

- [ ] Функц. тесты хендлеров create/update/delete + Maker (снапшоты подтягиваare title).
- [ ] Коммит.

## T9. Вкладка «Персонал»: список профилей + форма профиля (UI)

**Файлы (новые):**
- Контроллеры `Infrastructure/Controller/Profile/{ListAction,AddAction,UpdateAction,ShowAction,DeleteAction}.php` (тонкие; List → `GetPagedProfiles`, фильтр/поиск по ФИО; состояние фильтра в URL).
- Шаблоны `src/Shared/Infrastructure/Templates/cabinet/personnel/{index,form,show}.html.twig` — копировать раскладку ближайшего cabinet-аналога (напр. `cabinet/report/` или `admin/reports/counterparty/`), без новых классов. Форма: ФИО (3 поля), должность (async typeahead + quick-create), организация (Counterparty typeahead), отдел (select из дерева компании — зависит от выбранной организации), таб.№, дата приёма, размеры (группа полей). Кнопки create/edit/delete под `{% if canEdit %}` (`canEdit = is_granted('ROLE_ADMIN')`).
- Пункт меню «Персонал» — добавить в навигацию (нижняя таб-панель PWA-шелла / боковое меню; найти шаблон навигации, добавить ссылку под условием доступа как у других разделов). НЕ конфликтовать с существующим `/cabinet/profile` (это другой UI-хаб) — новый роут-префикс, напр. `/cabinet/personnel`.

- [ ] `yarn dev` + браузер: создать профиль целиком (должность quick-create, организация, отдел из дерева, размеры), правка, список с поиском по ФИО, серверная валидация (пустое ФИО / чужой отдел → сообщение в форме).
- [ ] Коммит.

## T10. `PersonnelAccessControl`

**Файл (новый):** `app/src/Personnel/Application/Service/AccessControl/PersonnelAccessControl.php` — `final readonly class` над `App\Shared\Application\Security\AccessGuard`. `canManage(): bool { return $this->guard->isManager(); }` (админ или система). Все мутирующие хендлеры (T4/T6/T8) вызывают `if(!$access->canManage()) throw new ForbiddenException();`.

- [ ] Функц. тест: без `authenticateAsSystem()`/не-админ → `ForbiddenException` на create; c системным принципалом → ок.
- [ ] Коммит.

## Финал Д1

- [ ] `./run check` зелёный (unit+functional в контейнере), тест-БД мигрирована; cleanup style/phpstan.
- [ ] `yarn dev`, браузерный смоук: должность quick-create, дерево отделов + начальник, профиль целиком, список/поиск, серверная валидация связок.
- [ ] Проверить: нет `dd()`/`var_dump`/закомментированного; `.DS_Store` не в staged.
- [ ] Коммиты партиями по смыслу (VO+DBAL / Position / Department / Profile / UI). Пуш/мерж — по апруву пользователя.
- [ ] Открытые данные (не код): перечень отделов/структура компании — заводит пользователь после деплоя.
