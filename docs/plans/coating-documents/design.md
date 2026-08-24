# Документы (заключения/сертификаты) — контекст `Certificates`

## Задача

Завести сущность «документ» (заключение об испытании / сертификат / протокол), которую
можно прикрутить к системе покрытий, а в будущем — к покрытию и другим сущностям. Документ
подтверждает что-то о владельце: прошёл испытания по стандарту, обязательный сертификат на
назначение (напр. огнезащита R120) и т.п.

Источник предметных данных: `~/Downloads/1. перечень заключений Литум изм. 04.05.2026.xlsx`
(«перечень заключений»), 12 листов. Каждая строка — заключение/протокол, привязанное к
конкретному составу системы, с полями: автор (лаборатория), дата, номер закл./протокола,
тип испытаний, «срок экспл., среда», прочие примечания, срок действия.

## Ключевое архитектурное решение

Документы — **отдельный bounded context `Certificates`** (`App\Certificates\`), со своими
тремя слоями Domain/Application/Infrastructure. НЕ внутри `Coatings`. На сущности Coatings
ссылаемся только по id (`referenceType`+`referenceId`) — кросс-контекстная ссылка по id,
без прямых объектных связей (чистый DDD).

Не путать с существующим контекстом `Documents` (тот в Elasticsearch, про внешние ссылки на
покрытия, к системам не привязан). `Certificates` — новый, в Postgres.

## Модель (зафиксировано с пользователем)

Два агрегата в контексте `Certificates`.

### Агрегат `Document`
`App\Certificates\Domain\Aggregate\Document\Document`

| Поле | Тип | Источник (Excel) |
|---|---|---|
| `id` | `Uuid` (передаётся в конструктор) | — |
| `references` | `list<Reference>` (VO, ≥1) | — (владельцы: системы/покрытия) |
| `kind` | `DocumentKind` enum | вид документа |
| `title` | `string` | номер закл./протокола (напр. `55-2023/ЦС ГСМ-ПК`) |
| `issuerId` | `Uuid` → `Issuer` | автор/лаборатория |
| `issuedAt` | `\DateTimeImmutable` | «точная дата» |
| `expiresAt` | `?\DateTimeImmutable` | «срок действия до …» |
| `testStandard` | `?string` (пока строка) | стандарт (ГОСТ/СП28/R120) |
| `subject` | `string` | «срок экспл., среда» |
| `description` | `?string` | свободное описание |
| `file` | `?string` | путь/ключ PDF в хранилище |
| `createdAt` | `\DateTimeImmutable` | — |
| `updatedAt` | `\DateTimeImmutable` | — |

Доменное поведение:
- `isExpired(): bool` — `expiresAt` в прошлом (null → всегда действует).
- Ссылки на владельцев: `addReference`/`removeReference`/`replaceReferences(Reference ...$refs)`,
  `references()`, `referencesTo(ReferenceType): list<Uuid>`.
- Инварианты (кидают `AppException`): непустой `title`; непустой `subject`;
  `expiresAt >= issuedAt`, если задан; maxLength на текстовых полях; **минимум одна ссылка**;
  без дублей ссылок (пара type+id уникальна в пределах документа).

`file` — только строка (путь/ключ). Загрузка/скачивание — инфраструктура (flysystem).

### VO `Reference`
`App\Certificates\Domain\Aggregate\Document\Reference` — `final readonly`:
`referenceType: ReferenceType` + `referenceId: Uuid`, равенство по значению (`equals`).
Один документ → много `Reference` (напр. один сертификат на несколько покрытий).

### Агрегат `Issuer`
`App\Certificates\Domain\Aggregate\Issuer\Issuer` — издатель документа (лаборатория/
институт/орган: ГосНИИГА, НПЦ Самара, ЛКП, ЦНИИТС). Поля: `id: Uuid` (передаётся),
`title: string` (+ maxLength + уникальность). Мини-CRUD и suggest, по новым конвенциям
(id в конструктор, без `*Finder`/`*Fetcher` — suggest прямо в репозитории, id-списки —
`StringCollection`).

### Enum-ы (`App\Certificates\Domain\Aggregate\Document\`)
- `DocumentKind`: `Conclusion` (заключение), `Certificate` (сертификат),
  `Protocol` (протокол), `Other` (другое).
- `ReferenceType`: `CoatingSystem`, `Coating` (перспектива).

Полиморфная привязка — коллекция мягких ссылок `Reference` (`referenceType`+`referenceId`,
без FK). Один документ → много владельцев (в т.ч. разных типов), минимум один. Хранение —
`jsonb`-колонка `references` через DBAL-тип (как VO-JSON в проекте: `DryingTimeSeries` и т.п.)
+ GIN-индекс; обратные выборки (документы владельца, счётчик, фильтр «есть документы») —
jsonb-containment `references @> '[{"type":"…","id":"…"}]'` внутри контекста `Certificates`.

## Принятые решения (развилки согласованы)

- **Отдельный контекст `Certificates`** (см. выше).
- **Связь**: many-to-many через коллекцию `Reference` на документе (1 документ → ≥1 владелец;
  1 владелец → много документов). Read-модель системы подтягивает документы через кросс-контекстный
  порт (не прямой доступ к таблице из доменного кода Coatings).
- **Файл**: PDF-скан, скачиваемый. В домене — `?string file`. Загрузка multipart + flysystem
  (свой адаптер/каталог), имя по `uuid`, скачивание отдельным экшеном. Только PDF, лимит
  размера. Удаление документа — hard-delete записи + файла.
- **UI**: только admin. В кабинете пока нет.
- **Issuer CRUD**: inline-выбор/создание в форме документа (suggest + «создать») + список издателей.
- **Read-модель системы**: карточка — список документов; список систем — счётчик; поиск систем —
  boolean-фильтр «есть документы: да/нет».
- **testType** — не заводим. **testStandard** — строка сейчас.
- **Просрочка**: доменный `isExpired()` + метка в UI.
- **Гибкий поиск** документов по `title`/`subject`/`description` — через Postgres FTS (tsvector),
  не LIKE.

## Обвязка нового контекста (инфраструктура)

- **doctrine.yaml** — новый mapping-блок (по образцу `Coatings`/`Proposals`):
  ```yaml
  Certificates:
    is_bundle: false
    type: xml
    dir: '%kernel.project_dir%/src/Certificates/Infrastructure/Database/ORM/Aggregate'
    prefix: 'App\Certificates\Domain\Aggregate'
    alias: Certificates
  ```
- **routes.yaml** — новый ресурс: `certificates: { resource: ../src/Certificates/Infrastructure/Controller, type: attribute }`.
- **services.yaml** — `App\: '../src/'` уже автозагружает контекст; отдельно — bind flysystem-сервиса
  в `DocumentFileStorage` и параметры каталога загрузок.
- **Миграции** — в общий каталог `app/src/Shared/Infrastructure/Database/Migrations/`.
- **Таблицы** — с префиксом контекста: `certificates_document`, `certificates_issuer`.
- **URL-префикс admin** — `/cabinet/certificate/...` (зеркаль стиля `/cabinet/coating/...`).
- **Общие переиспользуемые классы** (Shared): `Aggregate`, `AppException`, `StringCollection`,
  `Pager`/`PaginationResult`, `PrefixTsQueryBuilder`, `CommandHandlerInterface`/`QueryHandlerInterface`,
  шины команд/запросов, DQL-функции FTS (`TS_MATCH`/`TO_TSQUERY`/`TS_RANK_CD`).
- **Шаблоны** — в `Shared/Infrastructure/Templates/admin/certificate/...` (шаблоны общие по расположению).

## Декомпозиция (порядок = зависимости)

Отдельные самодостаточные планы (многодеплойная задача, правило проекта).

- **План 1 — `Issuer`** (`plan-1-issuer.md`): агрегат + admin CRUD + suggest.
- **План 2 — Подсистема `Document`** (`plan-2-document.md`): агрегат + enum-ы + полиморфная
  ссылка + файл (upload/download) + admin CRUD + FTS-поиск документов + миграция таблиц.
- **План 3 — Интеграция в систему** (`plan-3-system-integration.md`): кросс-контекстный порт;
  `documentCount` в списке систем, список документов на карточке/в модалке, boolean-фильтр
  «есть документы» в поиске систем. Правит горячие файлы Coatings (`list.html.twig`,
  view-factory, DTO, JS-модалка) — держим отдельным деплоем.
- **План 4 — Импорт из Excel** (`plan-4-import.md`): ETL. Для каждой строки: если такая система
  есть — вешаем документ; нет — создаём систему, затем документ. Зеркаль/расширяет существующую
  `app:coating-system:import` (`docs/plans/import-coating-systems.md`).

Зависимость: План 1 → План 2 → План 3; План 4 после Плана 2 (и Плана 1).

## Открытые вопросы Плана 4 (детальный брейншторм отдельно)

- Критерий матчинга «такая система уже есть» (по составу слоёв/толщинам/подложке/среде?).
- Поведение при ненайденном материале-`Coating` — вероятно как в `import-coating-systems.md`
  (`blocked` с причиной, прогон не падает).
- Форма импорта: консольная команда (предпочтительно) vs Doctrine-миграция.
- Парсинг xlsx: не тянуть PHP-зависимость; привести к нормализованному JSON вне репо, JSON
  закоммитить как источник истины (как для docx-импорта систем).

## Заметки по окружению (для реализации)

- Ветку под задачу заводим по правилу флоу (не работаем в `main`). SP28 влит в `main`
  (PR #21), дерево чистое — ответвляемся от актуального `main`.
- Тесты: unit на хосте, functional в контейнере (память проекта). Домены — unit на
  конструктор/инварианты; handler-ы — functional с реальной БД; mapper-ы — round-trip.
- Коммиты — только по явному апруву пользователя.
