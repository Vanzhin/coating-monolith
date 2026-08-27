# План 1 — Контекст `Certificates`: агрегат `Issuer` (+ bootstrap контекста)

Самодостаточный план. Общий контекст — `design.md`. Следующий — `plan-2-document.md`.
Это ПЕРВЫЙ план нового контекста `Certificates`, поэтому он же поднимает скелет контекста
и его обвязку (doctrine mapping, routes).

## Цель

Завести контекст `App\Certificates\` и агрегат `Issuer` (лаборатория/институт/орган,
выдавший заключение: ГосНИИГА, НПЦ Самара, ЛКП, ЦНИИТС) с admin-CRUD и typeahead-suggest.
Паттерн — `Coatings\Tag`, но по новым конвенциям: id в конструктор; suggest прямо в
репозитории (`ILIKE`), без `*Finder`/`*Fetcher`; id-списки — `StringCollection`.

## Bootstrap контекста (один раз, здесь)

- Каталоги `app/src/Certificates/{Domain,Application,Infrastructure}/...`.
- `app/config/packages/doctrine.yaml` — mapping-блок:
  ```yaml
  Certificates:
    is_bundle: false
    type: xml
    dir: '%kernel.project_dir%/src/Certificates/Infrastructure/Database/ORM/Aggregate'
    prefix: 'App\Certificates\Domain\Aggregate'
    alias: Certificates
  ```
- `app/config/routes.yaml` — ресурс:
  `certificates: { resource: ../src/Certificates/Infrastructure/Controller, type: attribute }`.
- `services.yaml` не трогаем: `App\: '../src/'` уже автозагружает контекст, хендлеры
  цепляются к шинам через `_instanceof` по интерфейсам (`CommandHandlerInterface`/`QueryHandlerInterface`).

## Модель

`Issuer` (`App\Certificates\Domain\Aggregate\Issuer\Issuer`, extends `App\Shared\Domain\Aggregate\Aggregate`):
- `public readonly Uuid $id;`, `private string $title;`
- `__construct(Uuid $id, string $title, IssuerSpecification $specification)`:
  `setTitle` + `$specification->uniqueTitle->satisfy($this)`.
- `setTitle`: `trim`, непустой → иначе `AppException`, `AssertService::maxLength($title, 255)`.
- Уникальность `title` — доменная спецификация + unique-constraint в БД.

Спецификации (зеркаль `Coatings/Tag/Specification/`):
- `IssuerSpecification` (обёртка `implements SpecificationInterface`, инжектит `UniqueTitleIssuerSpecification`).
- `UniqueTitleIssuerSpecification::satisfy(Issuer)` — `findOneByTitle`, бросает при чужом id.

## Файлы

### Domain
- `Certificates/Domain/Aggregate/Issuer/Issuer.php`
- `Certificates/Domain/Aggregate/Issuer/Specification/IssuerSpecification.php`
- `Certificates/Domain/Aggregate/Issuer/Specification/UniqueTitleIssuerSpecification.php`
- `Certificates/Domain/Repository/IssuerRepositoryInterface.php` — `add`, `findOneById`,
  `findByIds(StringCollection): array`, `findOneByTitle`, `findByFilter(IssuersFilter): PaginationResult`,
  `suggest(string $query, int $limit = 10): array`, `remove`.
- `Certificates/Domain/Repository/IssuersFilter.php` — `?Pager $pager`, `?string $title`.

### Application
- `Certificates/Application/DTO/Issuers/IssuerDTO.php` (+ `IssuerDTOTransformer`) — `id`, `title`.
- Command `CreateIssuer/` — `Command(string $title)`, `Handler implements CommandHandlerInterface`
  (генерит `Uuid::v7()`, `new Issuer`, `add`), `Result(id, title)`.
- Command `UpdateIssuer/` — `Command(id, title)`, `Handler` (findOneById → `setTitle` → re-satisfy → `add`), `Result`.
- Command `DeleteIssuer/` — `Command(id)`, `Handler` (findOneById → `remove`).
  Защита «нельзя удалить издателя с документами» — в Плане 2 (когда появится `Document`).
- Query `GetPagedIssuers/` — `Query(IssuersFilter)`, `Handler implements QueryHandlerInterface`,
  `Result(list<IssuerDTO>, Pager)`.
- Query `SuggestIssuers/` — `Query(string $query, int $limit = 10)`, `Handler` (репозиторий `suggest` напрямую),
  `Result(list<IssuerDTO>)`.

### Infrastructure
- `Certificates/Infrastructure/Repository/IssuerRepository.php` — `extends ServiceEntityRepository
  implements IssuerRepositoryInterface`, `parent::__construct($registry, Issuer::class)`.
  `suggest`: QB `WHERE LOWER(i.title) LIKE LOWER(:q)`, `:q = $query.'%'`, `orderBy title`, `setMaxResults`.
  `findByFilter`: Doctrine `Paginator` (как `TagRepository`).
- Контроллеры per-action, namespace `App\Certificates\Infrastructure\Controller\Issuer`,
  `#[IsGranted('ROLE_ADMIN')]`, префикс `/cabinet/certificate/issuer`:
  - `ListAction` — `GET`, Twig `admin/certificate/issuer/list.html.twig`.
  - `CreateIssuerAction` — `POST`, JSON `title`, `AppException`→422, `JsonResponse(['id','title'], 201)`
    (переиспользуется инлайн-созданием в форме документа — План 2).
  - `UpdateIssuerAction` — `POST /{id}`.
  - `DeleteIssuerAction` — `POST /{id}/delete`.
  - `SuggestIssuersAction` — `GET /suggest`, `q`/`limit` (clamp 1..25), пустой `q`→`[]`, `[{id,title}]`.
- ORM: `Certificates/Infrastructure/Database/ORM/Aggregate/Issuer.Issuer.orm.xml` — table
  `certificates_issuer`, `<id type="string" length="36"><generator strategy="NONE"/></id>`,
  `title` string(255), `<unique-constraint columns="title"/>`.
- Миграция `app/src/Shared/Infrastructure/Database/Migrations/Version*.php` — идемпотентно
  `CREATE TABLE IF NOT EXISTS certificates_issuer (id VARCHAR(36) PK, title VARCHAR(255) UNIQUE)`.

### Templates
- `app/src/Shared/Infrastructure/Templates/admin/certificate/issuer/list.html.twig` — зеркаль
  `admin/coating/tag/list.html.twig`. Кнопки создать/переименовать/удалить — по образцу ближайшего
  admin-списка (стили не сочиняем). JS — Stimulus в `app/assets/controllers/`, если нужен.

## Тесты
- `tests/Unit/Certificates/Domain/Aggregate/Issuer/IssuerTest.php` — пустой/длинный title → `AppException`; happy-path.
- Functional (реальная БД): `CreateIssuer`/`UpdateIssuer`/`DeleteIssuer`; `IssuerRepository::suggest`, `findByFilter`.

## Проверка
- Unit на хосте; functional в контейнере (память окружения).
- phpstan/cs-fixer по новым файлам; `bin/console doctrine:migrations:migrate -n`.
- Ручной прогон: создать издателя, найти через suggest, переименовать, удалить.
- `cd app && yarn dev`, если трогали JS/CSS/Twig.

## Открытые мелочи
- HTTP-методы update/delete — по образцу существующих admin-экшенов при реализации.
- `type`/категория издателя — пока НЕТ (в Excel это просто «автор»).
