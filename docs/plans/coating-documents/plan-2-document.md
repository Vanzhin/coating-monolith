# План 2 — `Certificates`: подсистема `Document` (агрегат + admin-CRUD + файл + FTS)

Самодостаточный план. Общий контекст — `design.md`. Зависит от `plan-1-issuer.md`
(ссылка `issuerId` → `Issuer`, контекст уже поднят). Интеграция в read-модель системы —
`plan-3-system-integration.md`.

Наш `Document` — контекст `Certificates` (Postgres), не путать с ES-контекстом `Documents`.
Связь «документ↔владелец» — через `referenceType`+`referenceId` (голый UUID + enum), без FK.

## Модель

`Document` (`App\Certificates\Domain\Aggregate\Document\Document`, extends `Aggregate`).
Поля/типы — см. `design.md`. Поведение:
- id передаётся в конструктор (`Uuid`).
- `isExpired(?\DateTimeImmutable $now = null): bool`.
- Ссылки: `addReference(Reference)`, `removeReference(Reference)`,
  `replaceReferences(Reference ...$refs)` (вариадик), `references(): list<Reference>`,
  `referencesTo(ReferenceType): list<Uuid>`.
- Инварианты (`AppException`): `setTitle` непустой + maxLength(255); `setSubject` непустой + maxLength;
  `setDescription`/`setTestStandard` maxLength; `expiresAt >= issuedAt`, если задан;
  **минимум одна ссылка**; без дублей (пара type+id уникальна в документе).
- `file: ?string` — домен файлом не оперирует.

VO/enum-ы (`App\Certificates\Domain\Aggregate\Document\`):
- `Reference` — `final readonly` (`ReferenceType $referenceType`, `Uuid $referenceId`),
  `equals(Reference): bool`, `fromArray`/`jsonSerialize` для jsonb-хранения.
- `DocumentKind`: `Conclusion`, `Certificate`, `Protocol`, `Other` + `label(): string`.
- `ReferenceType`: `CoatingSystem`, `Coating` + `label()`.

## Поиск документов (FTS по `title`/`subject`/`description`)

Все поисковые поля — локальные колонки документа (без джойнов), поэтому вместо
application-upsert-проектора берём встроенную **Postgres generated tsvector-колонку**
(меньше кода, нет события/бэкофилла):
```sql
search_tsvector tsvector GENERATED ALWAYS AS (
  to_tsvector('russian',
    coalesce(title,'') || ' ' || coalesce(subject,'') || ' ' || coalesce(description,''))
) STORED
```
+ GIN-индекс. Запрос — в `DocumentRepository::findByFilter` через DBAL:
`search_tsvector @@ TO_TSQUERY('russian', :tsquery)`, ранжирование `TS_RANK_CD`, tsquery —
общий `PrefixTsQueryBuilder`. Пустой запрос — выборка без FTS. Без класса `*Finder`.

Обоснование отклонения от паттерна `coating_system_search` (там app-upsert): в tsvector систем
попадают производные данные по слоям/тегам (нужны джойны) — generated-колонкой не выразить.
Здесь источник — три локальных поля, поэтому generated-колонка проще и достаточна.

## Файловое хранилище

- `oneup_flysystem.yaml` — адаптер+filesystem `document_scans` (local, `%document_scans_upload_dir%`).
- `services.yaml` — параметры `document_scans_upload`/`document_scans_upload_dir` (зеркаль
  `general_proposal_template_upload*`) + bind:
  ```yaml
  App\Certificates\Infrastructure\Storage\DocumentFileStorage:
    arguments: { $filesystem: '@oneup_flysystem.document_scans_filesystem' }
  ```
  (Автоалиаса для flysystem-сервисов нет — инъекция явная по id.)
- `Certificates/Infrastructure/Storage/DocumentFileStorage.php` — обёртка над `FilesystemOperator`:
  `store(UploadedFile): string` (имя `{uuid}.pdf`, `write($name, $file->getContent())`),
  `delete`, `readStream`, `exists`.
- Валидация файла — своя (в проекте нет): `Assert\File(maxSize: '15M', mimeTypes: ['application/pdf'])`
  в validation-коллекции маппера. Только PDF.

## Файлы

### Domain
- `Certificates/Domain/Aggregate/Document/Document.php`
- `Certificates/Domain/Aggregate/Document/DocumentKind.php` (enum + label)
- `Certificates/Domain/Aggregate/Document/ReferenceType.php` (enum + label)
- `Certificates/Domain/Repository/DocumentRepositoryInterface.php` — `add`, `findOneById`, `remove`,
  `findByFilter(DocumentsFilter): PaginationResult` (FTS + фильтры),
  `findByReference(Reference): array` (`list<Document>` — jsonb containment `references @> [...]`),
  `countByReferences(ReferenceType, StringCollection): array<string,int>` (для read-модели, План 3;
  `jsonb_array_elements` + group).
- `Certificates/Domain/Repository/DocumentsFilter.php` — `?Pager`, `?string $query` (FTS),
  `?Reference $reference` (фильтр по одному владельцу, jsonb-containment), `?DocumentKind`, `?string $issuerId`.

### Application
- `Certificates/Application/DTO/Documents/DocumentDTO.php` (+ `DocumentDTOTransformer`) — все поля
  + `references` (`list<{referenceType, referenceId}>`), `issuerTitle`, `kindLabel`, `isExpired`, `hasFile`/`downloadUrl`.
- Command `CreateDocument/` — `Command` (поля + `list<{type,id}> $references` + `?UploadedFile $file`),
  `Handler` (собрать `Reference[]`; файл → `store`; `new Document(..., ...$references)`; `add`), `Result(id)`.
- Command `UpdateDocument/` — `Command` (+ `references`), `Handler` (сеттеры; `replaceReferences(...)`;
  при новом файле — `store` нового + `delete` старого).
- Command `DeleteDocument/` — `Handler` (`storage->delete(file)` если есть → `remove`).
- Query `GetPagedDocuments/` — `Query(DocumentsFilter)`, `Handler`, `Result(list<DocumentDTO>, Pager)`.
- Query `GetDocument/` — `Query(id)`, `Handler`, `Result(?DocumentDTO)`.
- `Certificates/Application/UseCase/PublicUseCaseInteractor.php` (или аналог) — публичный фасад
  запросов контекста; через него План 3 читает документы систем (зеркаль `Coatings\...\PublicUseCaseInteractor`).

### Infrastructure
- `Certificates/Infrastructure/Repository/DocumentRepository.php` — `findByFilter` (DBAL FTS +
  фильтры + пагинация), `findByReference`, `countByReferences`.
- `Certificates/Infrastructure/Storage/DocumentFileStorage.php` (см. выше).
- `Certificates/Infrastructure/Database/DBAL/DocumentReferencesType.php` — DBAL-тип для `jsonb`
  (наследует `JsonType`; `convertToPHPValue` → `list<Reference>` через `Reference::fromArray`,
  `convertToDatabaseValue` → `parent::` над `jsonSerialize`). Регистрация в `doctrine.yaml`
  `types: { document_references: App\Certificates\Infrastructure\Database\DBAL\DocumentReferencesType }`.
- Контроллеры per-action (namespace `App\Certificates\Infrastructure\Controller\Document`,
  `#[IsGranted('ROLE_ADMIN')]`, префикс `/cabinet/certificate/document`) — зеркаль
  `Coatings/Controller/Coating/{Add,Update,List}Action`:
  - `ListAction` — `GET`, Twig `admin/certificate/document/list.html.twig` (строка FTS + фильтры).
  - `AddAction` — `GET` форма + `POST` создать (multipart).
  - `UpdateAction` — `GET` форма + `POST` обновить.
  - `DeleteAction` — `POST /{id}/delete`.
  - `DownloadAction` — `GET /{id}/download` → `StreamedResponse` из `readStream`,
    `Content-Disposition: attachment; filename="{title}.pdf"`, 404 если `file` пуст.
- `Certificates/Infrastructure/Mapper/DocumentMapper.php` — pure shape (форма↔DTO; список
  `references` ↔ повторяемые строки формы) + `getValidationCollection` (`Assert\*` + `Assert\File`
  PDF + `Assert\Count(min: 1)` на `references` как структурная проверка). Без бизнес-правил
  (инвариант «≥1 ссылка» всё равно живёт в домене).
- ORM: `Certificates/Infrastructure/Database/ORM/Aggregate/Document.Document.orm.xml` — table
  `certificates_document`, `id` string(36) NONE, `references` — `type="document_references"`
  (jsonb DBAL-тип, column `jsonb`), `kind` — string с `enum-type`, `issuer_id` string(36),
  даты datetime_immutable (`expires_at` nullable), `subject`/`description` text,
  `test_standard`/`file` string nullable. `search_tsvector` — generated-колонка (в XML не маппим).
- Миграция `Version*.php` — идемпотентно `CREATE TABLE IF NOT EXISTS certificates_document (...)`
  c `references jsonb NOT NULL DEFAULT '[]'` + generated `search_tsvector` + GIN на `search_tsvector`
  + **GIN на `references`** (обратные containment-выборки Плана 3) + индексы `issuer_id`, `kind`.

### Templates / JS
- `admin/certificate/document/list.html.twig` — зеркаль admin-списка Coatings: строка FTS,
  фильтры (kind/issuer/reference), строки (вид, издатель, даты, бейдж «действует/просрочен»,
  ссылка download, edit/delete).
- `admin/certificate/document/form.html.twig` — зеркаль формы Coatings: поля документа;
  `issuer` — typeahead+создать (эндпоинты Issuer из Плана 1); **список ссылок** — повторяемые
  строки (add/remove), каждая: `referenceType` (select) + `referenceId` (typeahead: система через
  существующий suggest систем; покрытие — suggest покрытий); минимум одна строка;
  `kind` select; даты; upload PDF; `testStandard`; `subject`; `description`.
- JS `app/assets/controllers/document_form_controller.js` — динамические строки ссылок
  (add/remove, вариадик-имена `references[]`), typeahead issuer/reference, имя выбранного файла.
  HTML строки-ссылки не дублировать — `<template>` (правило проекта). После правок — `cd app && yarn dev`.

## Тесты
- Unit: `Document` (инварианты, в т.ч. «≥1 ссылка» и «без дублей», `addReference`/`removeReference`/
  `replaceReferences`, `isExpired`); `Reference` (`equals`, `fromArray`/`jsonSerialize`);
  `DocumentKind`/`ReferenceType` (label).
- Unit: `DocumentReferencesType` round-trip (PHP `list<Reference>` ↔ jsonb).
- Functional: `CreateDocument`/`UpdateDocument`/`DeleteDocument` (в т.ч. запись/удаление файла
  через локальный flysystem во временный каталог); `DocumentRepository::findByFilter` (FTS + фильтры + пагинация),
  `findByReference` (jsonb containment), `countByReferences` (группировка по владельцу).
- Mapper round-trip (в т.ч. список ссылок).

## Проверка
- Unit на хосте; functional в контейнере; phpstan/cs-fixer; миграция накатывается.
- Ручной прогон: создать документ с PDF на систему (по id), найти через FTS, скачать,
  заменить файл, удалить (файл удалён), проверить бейдж просрочки. `cd app && yarn dev`.

## Открытые мелочи
- `enum-type` в XML vs строка+конвертация в трансформере — по факту версии Doctrine.
- `referenceId`: мягкая проверка существования владельца в хендлере (не FK), `AppException` если не найден.
