# Поиск систем покрытий — План 2: переключение чтения и UI

Второй из двух деплоев. Опирается на read-модель кэша, наполненную планом 1 (`plan-1-domain-and-cache-redesign.md`). Пользователь получает полноценный поиск систем: строка q (FTS), фасеты (substrate, compliance, tags, applicationMinTemp, minBuildingTimeAt20), сортировки, infinite scroll, typeahead. Отдельный `SearchByCompliance` вычищается.

## Зачем разделено

План 1 создал две кэш-таблицы, обновляемые event-driven через subscriber-ы:
- `coating_system_search` (1:1 с системой) — колонки: `system_id`, `min_building_time_at_20_minutes`, `max_layer_application_min_temp`, `search_tsvector` (+ GIN на tsvector, btree на min/max).
- `coating_system_compliance` (1:N) — тройки `(system_id, standard, category, durability)` с индексом для фасета по стандартам.

Обе таблицы наполнены рабочей data через backfill-команду `app:coating-system:rebuild-search-cache`. Теперь можно завести на них новый Query и переключить UI.

## Что входит в план 2

**Application**:
- Новый `SearchCoatingSystemsQuery` + Handler.
- Расширенный `CoatingSystemsFilter` (search, substrates[], standards[], categories[], durabilities[], tagIds[], applicationMinTemp: RangeFilter, minBuildingTimeAt20: RangeFilter, sort).
- Новый `CoatingSystemSort` enum (DEFAULT, TITLE_ASC, TITLE_DESC).
- Новый `SearchCoatingSystemsForSuggestQuery` + Handler — лёгкий JSON для typeahead.

**Infrastructure**:
- Новый `CoatingSystemFinder` в `Coatings/Infrastructure/Search/` по образцу `CoatingFinder`. Работает с JOIN трёх таблиц:
  - `coating_system` — основные атрибуты системы.
  - `coating_system_search` — FTS-документ, min/max для range-фасетов и сортировки.
  - `coating_system_compliance` — фасет по стандартам (JOIN только когда есть standards/categories/durabilities-фильтр).
  Использует существующий `App\Shared\Infrastructure\Database\FullTextSearch\PrefixTsQueryBuilder`.
- Новый `SuggestAction` для CoatingSystem (аналог существующего для покрытий).
- Полная переписка `CoatingSystem/ListAction` — принимает q + все фасеты через URL, отдаёт полный шаблон или partial-fragment для infinite scroll.

**UI**:
- Новый partial `Templates/components/_search_toolbar.html.twig` — тонкий переиспользуемый блок: поле q + submit + сброс. Используется и в новом поиске систем, и в существующем поиске покрытий (`admin/coating/coating/index.html.twig` — заменить inline-разметку).
- Новый файл макросов `Templates/components/facets.twig`:
  - `single_select_chip(label, name, options, current)`.
  - `multi_select_chip(label, name, options, current[])`.
  - `tag_chip(name, tagifyEndpoint, currentTagIds)`.
- Полностью переписанный `Templates/cabinet/coating/coating_system/list.html.twig` — по образцу `admin/coating/coating/index.html.twig`, использует embed `components/list_page.html.twig`, включает `_search_toolbar`, `range_filter_card`, `infinite_list`, макросы `facets.twig`.
- Обновление формы `Templates/cabinet/coating/coating_system/form.html.twig` — добавить поле «Теги» через Tagify (маппер уже принимает `tagIds` — план 1).
- Переиспользование существующих Stimulus-контроллеров: `chip_facets`, `range_filter`, `async_typeahead`, `infinite_list`, `coating_tags`. Новых JS-контроллеров не создаём.

**DTO CoatingSystemDTO** для карточки/списка:
- `compliance` заполняется **runtime через `ComplianceEvaluator`** (не из таблицы) — DTO Transformer вызывает `$system->complianceMatches($evaluator)`. Всегда актуально, независимо от лага кэша. Кэш-таблица — только для поиска.
- `minBuildingTimeAt20Minutes`, `maxLayerApplicationMinTemp` — runtime-методы агрегата (`$system->minBuildingTimeAt20Minutes()` и т.п.).

**Уборка `SearchByCompliance`**:
- Удалить `Coatings/Infrastructure/Controller/CoatingSystem/SearchByComplianceAction.php`.
- Удалить `Coatings/Infrastructure/Api/CoatingSystem/SearchByComplianceApiAction.php`.
- Удалить `Coatings/Application/UseCase/Query/SearchCoatingSystemsByCompliance/*`.
- Удалить шаблон `Templates/cabinet/coating/coating_system/search_by_compliance.html.twig`.
- Удалить тесты: `Functional/Coatings/Infrastructure/Controller/CoatingSystem/SearchByComplianceActionTest.php`, `Api/CoatingSystem/SearchByComplianceApiActionTest.php`, `Application/UseCase/Query/SearchCoatingSystemsByComplianceQueryHandlerTest.php`.
- Удалить роут `app_cabinet_coating_system_search_by_compliance` и `app_api_coating_system_by_compliance` из `app/config/routes.yaml` (если явно там; иначе — с удалением action-ов исчезают сами через `#[Route]`).
- Существующий `ListCoatingSystemsQuery` + Handler + функциональный тест — тоже удалить (заменяется `SearchCoatingSystemsQuery`).

**Меню**:
- `Templates/base.html.twig` — пункт «Теги покрытий» → «Теги» (текст, URL остаётся тот же, т.к. таблицу не переименовали).

## Что НЕ входит в план 2

- Compare tray для систем + отдельная страница сравнения — отдельная задача (следующий деплой после плана 2).
- Preset-ссылки на dashboard для систем — вне скоупа обоих планов.
- `TagRenamed` event / инвалидация tsvector при переименовании тега — если понадобится, отдельным подшагом.

## Изменения по слоям

### Domain

Изменить:
- `Domain/Repository/CoatingSystemsFilter.php` — новые поля: `search: ?SearchQuery`, `substrates: Substrate[]`, `standards: ComplianceStandard[]`, `categories: IsoCorrosivityCategory[]`, `durabilities: IsoDurability[]`, `tagIds: string[]`, `applicationMinTemp: ?RangeFilter`, `minBuildingTimeAt20: ?RangeFilter`, `sort: CoatingSystemSort`.
- `Domain/Repository/CoatingSystemRepositoryInterface.php` — метод `findByIds(array $ids): array` (плюс уже есть `findByLayerCoatingId`, `findAll`, `findById`).

Создать:
- `Domain/Repository/CoatingSystemSort.php` (enum: DEFAULT, TITLE_ASC, TITLE_DESC).

### Application

Создать:
- `Application/UseCase/Query/SearchCoatingSystems/SearchCoatingSystemsQuery.php` + `Handler.php`.
- `Application/UseCase/Query/SearchCoatingSystemsForSuggest/SearchCoatingSystemsForSuggestQuery.php` + `Handler.php` — лёгкий JSON (id + title).

Изменить:
- `Application/DTO/CoatingSystems/CoatingSystemDTOTransformer.php` — заполнять `compliance` runtime через `ComplianceEvaluator`, `minBuildingTimeAt20Minutes` / `maxLayerApplicationMinTemp` через runtime-методы агрегата (не читать из таблицы).

Удалить:
- `Application/UseCase/Query/ListCoatingSystems/` (папка целиком).
- `Application/UseCase/Query/SearchCoatingSystemsByCompliance/` (папка целиком).

### Infrastructure

Создать:
- `Infrastructure/Search/CoatingSystemFinder.php` — QueryBuilder-конструктор фильтров, FTS через `PrefixTsQueryBuilder`, сортировки, пагинация. JOIN `coating_system` + `coating_system_search` (всегда) + `coating_system_compliance` (только при compliance-фильтре, через EXISTS/JOIN DISTINCT). Возвращает `SearchResult<string>` (id + total).
- `Infrastructure/Controller/CoatingSystem/SuggestAction.php` — GET, JSON, использует `SearchCoatingSystemsForSuggestQuery`, роут `/cabinet/coating/coating-system/suggest`, имя `app_cabinet_coating_system_suggest`.

Изменить:
- `Infrastructure/Controller/CoatingSystem/ListAction.php` — переписать полностью: принимает q + все фасеты из URL, конвертирует часы→минуты для minBuildingTimeAt20, отдаёт `SearchCoatingSystemsQuery`, рендерит полный шаблон или partial-fragment (при `?partial=1`).
- `Infrastructure/Repository/CoatingSystemRepository.php` — метод `findByIds(array $ids): array` с eager-load слоёв и тегов.

Удалить:
- `Infrastructure/Controller/CoatingSystem/SearchByComplianceAction.php`.
- `Infrastructure/Api/CoatingSystem/SearchByComplianceApiAction.php`.

### UI (Twig + JS)

Создать:
- `Templates/components/_search_toolbar.html.twig`.
- `Templates/components/facets.twig` — макросы single/multi-select chip + tag chip.

Изменить:
- `Templates/cabinet/coating/coating_system/list.html.twig` — полная переписка.
- `Templates/cabinet/coating/coating_system/form.html.twig` — поле «Теги».
- `Templates/admin/coating/coating/index.html.twig` — заменить inline-строку поиска на `{% include 'components/_search_toolbar.html.twig' %}`.
- `Templates/base.html.twig` — «Теги покрытий» → «Теги».

Удалить:
- `Templates/cabinet/coating/coating_system/search_by_compliance.html.twig`.

Stimulus: **новых контроллеров нет**. Возможные точечные правки существующих `chip_facets_controller.js` / `range_filter_controller.js` под пару edge-case (обсудить при появлении).

### БД (миграция)

Ничего. Все нужные поля наполнены планом 1.

## Порядок шагов реализации

Каждый шаг → показ пользователю → апрув → следующий.

1. **Application: Query + Filter + Sort + typeahead** — `SearchCoatingSystemsQuery` + Handler, `SearchCoatingSystemsForSuggestQuery` + Handler, расширенный `CoatingSystemsFilter`, `CoatingSystemSort`. Функциональные тесты хендлеров.
2. **Infrastructure: Finder + Repository** — `CoatingSystemFinder` (JOIN кэш-таблиц), метод `findByIds` в репозитории. Тесты Finder.
3. **DTO Transformer runtime** — `CoatingSystemDTOTransformer` заполняет `compliance` через evaluator и min/max через методы агрегата. Обновить существующий тест transformer-а.
4. **UI-partials: `_search_toolbar` и `facets.twig`** — новые файлы, `admin/coating/coating/index.html.twig` перешит на toolbar. Визуальная проверка в браузере поиска покрытий (не должно ничего сломаться).
5. **UI-шаблон списка систем + `SuggestAction` + переписанный `ListAction`** — новый `list.html.twig`, новый action Suggest, переписанный ListAction. Функциональные тесты обоих action-ов. Визуальная проверка в браузере.
6. **Форма системы с полем «Теги» (UI)** — обновление `form.html.twig`. Маппер уже принимает `tagIds` (сделано в плане 1). Визуальная проверка формы + функциональный тест что теги реально сохраняются через форму.
7. **Уборка `SearchByCompliance`** — удалить action-ы, Query, шаблон, тесты, роуты. Прогон всех тестов — должно быть чисто.
8. **Меню**: `base.html.twig` — «Теги покрытий» → «Теги». Визуальная проверка.

## Error handling

- Ошибки формы (некорректный range, битый tag id) → `AppException` в domain → 422 + `<div class="alert alert-danger">` в шаблоне.
- Пустой результат — плейсхолдер «Ничего не найдено» (использовать существующий из списка покрытий).
- Кэш-таблица не совпала с реальностью (баг event-subscriber-а) — фильтр может ложно исключить систему; в карточке всё равно показываются runtime-данные. Инвалидация — руками через `app:coating-system:rebuild-search-cache`.

## Тесты

**Unit** (`app/tests/Unit/Coatings/...`):
- `Domain/Repository/CoatingSystemSortTest` — базовое покрытие enum-а.
- `Domain/Repository/CoatingSystemsFilterTest` — если фильтр обрастёт логикой (сейчас плоский struct, возможно не нужен).

**Functional** (`app/tests/Functional/Coatings/...`):
- Создать: `Application/UseCase/Query/SearchCoatingSystemsQueryHandlerTest` — q, каждый фасет, комбинации, сортировки, пагинация.
- Создать: `Application/UseCase/Query/SearchCoatingSystemsForSuggestQueryHandlerTest`.
- Создать: `Infrastructure/Search/CoatingSystemFinderTest` — прямой SQL-тест JOIN-а трёх таблиц.
- Создать: `Infrastructure/Controller/CoatingSystem/SuggestActionTest`.
- Обновить: `Infrastructure/Controller/CoatingSystem/ListActionTest` — q в URL, чипы, infinite scroll partial.
- Обновить: `Application/DTO/CoatingSystems/CoatingSystemDTOTransformerTest` — compliance через evaluator, min/max через runtime-методы.
- Удалить: `Application/UseCase/Query/ListCoatingSystemsQueryHandlerTest`, `SearchCoatingSystemsByComplianceQueryHandlerTest`, `Infrastructure/Controller/CoatingSystem/SearchByComplianceActionTest`, `Infrastructure/Api/CoatingSystem/SearchByComplianceApiActionTest`.

## Файлы (сводка)

**Создать**:
- `app/src/Coatings/Domain/Repository/CoatingSystemSort.php`.
- `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystems/{Query,Handler}.php`.
- `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystemsForSuggest/{Query,Handler}.php`.
- `app/src/Coatings/Infrastructure/Search/CoatingSystemFinder.php`.
- `app/src/Coatings/Infrastructure/Controller/CoatingSystem/SuggestAction.php`.
- `app/src/Shared/Infrastructure/Templates/components/_search_toolbar.html.twig`.
- `app/src/Shared/Infrastructure/Templates/components/facets.twig`.

**Изменить**:
- `app/src/Coatings/Domain/Repository/CoatingSystemsFilter.php` — новые поля.
- `app/src/Coatings/Domain/Repository/CoatingSystemRepositoryInterface.php` — `findByIds`.
- `app/src/Coatings/Infrastructure/Repository/CoatingSystemRepository.php` — реализация `findByIds`.
- `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTOTransformer.php` — runtime compliance/min/max.
- `app/src/Coatings/Infrastructure/Controller/CoatingSystem/ListAction.php` — полная переписка.
- `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/list.html.twig` — полная переписка.
- `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/form.html.twig` — поле «Теги».
- `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig` — переход на `_search_toolbar`.
- `app/src/Shared/Infrastructure/Templates/base.html.twig` — переименование пункта меню.

**Удалить**:
- `app/src/Coatings/Application/UseCase/Query/ListCoatingSystems/` (папка).
- `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystemsByCompliance/` (папка).
- `app/src/Coatings/Infrastructure/Controller/CoatingSystem/SearchByComplianceAction.php`.
- `app/src/Coatings/Infrastructure/Api/CoatingSystem/SearchByComplianceApiAction.php`.
- `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/search_by_compliance.html.twig`.
- Соответствующие функциональные и unit-тесты.

## Cross-ref

- План 1: `plan-1-domain-and-cache-redesign.md` — определил домен + инфраструктуру кэша, на которые опирается этот план.
- Следующая задача (после плана 2): compare tray + страница сравнения систем — отдельный план.
