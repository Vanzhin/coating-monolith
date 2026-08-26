# План 6 — Список документов под единый стандарт (чипы-фасеты, шторка, лента, карточки, модалка, сортировка)

Самодостаточный план. Зависит от `plan-2-document.md` (агрегат `Document` + его запросы существуют).
Зеркалит архитектуру списка систем (`cabinet/coating/coating_system/list.html.twig`). Отдельный деплой.
**Делит новые Coatings-эндпоинты (system by-ids + system preview) с `plan-7-document-references.md`** —
кто первым деплоится, тот их и добавляет; второй переиспользует. Меню — `plan-5-nav-groups.md`.

## Цель

Привести `/cabinet/certificate/document` к тому же UX, что списки систем/покрытий:
- чип-ряд активных фильтров + пин-чипы (сортировка, «Все фильтры»);
- шторка `#allFiltersOffcanvas` (полная форма, сводка активных, сброс/применить);
- бесконечная лента (`?partial=1` + `infinite-list`), `<noscript>` → пейджер;
- карточки-партиал + модалка-превью документа;
- сортировка: по дате, по издателю, по названию.

**Фасеты:** Вид (kind), Издатель (issuerId), Статус срока (действует/просрочен/бессрочный),
Стандарт испытания (testStandard). Полнотекст `q` (title/subject/description) — всегда.

## Текущее состояние

Плоский серверный список (`admin/certificate/document/index.html.twig`): 3 инпута (q, kind, issuerId),
обычный пейджер, строки-плашки. Инлайн-логика в `ListAction` (нет RequestMapper/ViewFactory).
`DocumentsFilter` умеет: query(FTS), kind, issuerId, reference. Репозиторий `DocumentRepository::findByFilter`
(raw DBAL): FTS (`PrefixTsQueryBuilder`, ранк по `TS_RANK_CD`, tsvector = title+subject+description),
kind, issuer, reference(jsonb `@>`). `test_standard` НЕ в FTS и не фильтруется. Статус срока не фильтруется.

## Шаги

### Domain / Repository
1. `Certificates/Domain/Repository/DocumentsFilter.php` — добавить: `?DocumentExpiryStatus $status`,
   `?string $testStandard`, `DocumentSort $sort` (дефолт — по дате). kind/issuerId/query уже есть.
2. Новые enum'ы (человекочитаемые, `label()`):
   - `DocumentExpiryStatus` — `Valid` (действует), `Expired` (просрочен), `Perpetual` (бессрочный).
   - `DocumentSort` — `DateDesc` (по дате, свежие сначала), `IssuerAsc` (по издателю), `TitleAsc` (по названию).
   Кладём в `Certificates/Domain/Repository/` рядом с фильтром.
3. `Certificates/Infrastructure/Repository/DocumentRepository.php::findByFilter` — добавить:
   - `applyStatus`: Expired = `expires_at IS NOT NULL AND expires_at < :now`; Valid = `expires_at >= :now`;
     Perpetual = `expires_at IS NULL`.
   - `applyTestStandard`: `d.test_standard = :ts`.
   - `applySort`: DateDesc → `issued_at DESC`; IssuerAsc → JOIN `certificates_issuer` → `ORDER BY issuer.title`;
     TitleAsc → `d.title ASC`. Приоритет: явная сортировка переопределяет ранк; пустой sort + q → по ранку;
     пустой sort без q → `issued_at DESC`.
   - `distinctTestStandards(): list<string>` — `SELECT DISTINCT test_standard ... WHERE test_standard IS NOT NULL ORDER BY 1`
     (источник значений фасета).

### Application
4. `GetPagedDocumentsQuery` + Handler — принять `status`, `testStandard`, `sort`; прокинуть в `DocumentsFilter`.

### Infrastructure
5. `Certificates/Infrastructure/Mapper/DocumentListRequestMapper.php` (новый) — request → `DocumentsFilter`
   (q, kind, issuerId, status, testStandard, sort, page). Зеркаль `CoatingSystemListRequestMapper`.
6. `Certificates/Infrastructure/View/DocumentListViewFactory.php` (новый) — `build($request, $result)` →
   опции фасетов (kinds=enum cases, issuers=GetPagedIssuers, testStandards=repo.distinctTestStandards,
   statuses/sorts=enum cases), активные значения, `activeFacetsCount`, флаги. Зеркаль
   `CoatingSystemListViewFactory`.
7. `Certificates/Infrastructure/Controller/Document/ListAction.php` — тонкий: RequestMapper → Query →
   ViewFactory → render; при `?partial=1` рендерить голый `_list_cards`. Инлайн-логику убрать.

### Templates
8. Переписать `admin/certificate/document/index.html.twig` по образцу `coating_system/list.html.twig`:
   чип-ряд + `#allFiltersOffcanvas` + `infinite_list` + модалка `#documentModal`. Переиспользуем
   `components/_search_toolbar`, `infinite_list`, `list_page`, `pager`.
9. Новый `admin/certificate/document/_list_cards.html.twig` (батч карточек) по образцу `_list_cards` систем.
   Карточка: заголовок, бейдж вида, бейдж статуса срока, строка «издатель · issuedAt · testStandard», subject,
   кнопка скачать (если hasFile), edit/delete, чипы привязанных референсов (гидрируются by-ids), payload модалки.
10. Пин-чип «Сортировка» (дропдаун) как у систем: по дате / по издателю / по названию.

### JS
11. Переиспользовать `chip_facets`, `infinite_list`, `async_typeahead` (issuer/testStandard — typeahead
    в шторке при многих значениях, иначе select — решить при реализации).
12. Новый `document_preview_controller.js` — заполнить `#documentModal` из `data-payload` (вид, издатель,
    даты, статус, стандарт, тема, описание, скачать). Блок референсов гидрируется by-ids и кликается в
    превью: система → system-preview-фрагмент, покрытие → coating-preview-фрагмент (fragment-loader, не payload).

### Migration
13. `CREATE INDEX IF NOT EXISTS idx_certificates_document_expires_at ON certificates_document (expires_at)` —
    идемпотентно (под статус срока). testStandard-индекс — опц.

### Shared (общее с plan-7) — новые браузерные Coatings-эндпоинты (ациклично, `Coatings → Certificates`)
- `Coatings/Infrastructure/Controller/CoatingSystem/ByIdsAction.php` → `app_cabinet_coating_system_by_ids`
  → `{items:[{id,title}]}` (зеркаль Coating `ByIdsAction`; query `GetCoatingSystemsByIds`). Для гидрации id→название.
- `Coatings/Infrastructure/Controller/CoatingSystem/PreviewAction.php` → `app_cabinet_coating_system_preview`
  → HTML-фрагмент модалки (зеркаль Coating `PreviewAction`). Для клика по референсу-системе.
- Coating suggest/by-ids/preview уже есть.

## Тесты
- Functional `ListAction`: фильтры kind/issuer/status/testStandard/q, сортировки, `?partial=1`. Реальная БД.
- Unit `DocumentListRequestMapper` (request→filter), `DocumentListViewFactory` (опции/активные/счётчик),
  новые enum'ы (`label`).
- Functional `DocumentRepository::findByFilter` — status/testStandard/sort.

## Проверка
- Unit на хосте; functional в контейнере; cs-fixer/phpstan в контейнере. `cd app && yarn dev` (JS/Twig).
- Ручной прогон: чипы, шторка, лента, модалка, сортировка, гидрация референсов, сброс чипов
  (программный сабмит только `requestSubmit()` — память про обход chip-facets merge).
