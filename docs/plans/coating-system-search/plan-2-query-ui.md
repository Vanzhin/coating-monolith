# Поиск систем покрытий — План 2: переключение чтения и UI

Второй из двух деплоев. Опирается на read-модель кэша, наполненную планом 1 (`plan-1-domain-and-cache-redesign.md`). Пользователь получает полноценный поиск систем: строка q (FTS), фасеты (substrate, compliance-каскад, tags, applicationMinTemp, minApplicationTimeAt20), сортировки, infinite scroll, typeahead. Отдельный `SearchByCompliance` вычищается.

## Зачем разделено

План 1 создал две кэш-таблицы, обновляемые event-driven через subscriber-ы:
- `coating_system_search` (1:1 с системой) — колонки: `system_id`, `min_building_time_at_20_minutes` (→ переименуется в `min_application_time_at_20_minutes` первым шагом плана 2), `max_layer_application_min_temp`, `search_tsvector` (+ GIN на tsvector, btree на min/max).
- `coating_system_compliance` (1:N) — тройки `(system_id, standard, category, durability)` с индексом для фасета по стандартам.

Обе таблицы наполнены рабочей data через backfill-команду `app:coating-system:rebuild-search-cache`. Теперь заводим на них новый Query и переключаем UI.

## Что входит в план 2

**Rename** (первым шагом): `minBuildingTimeAt20Minutes` → `minApplicationTimeAt20Minutes` по всему коду (метод агрегата + колонка + индекс + миграция + тесты + SQL в кэш-репе + описание rebuild-команды).

**Application**:
- Новый `SearchCoatingSystemsQuery` + Handler.
- Расширенный `CoatingSystemsFilter` (search, substrates[], standard, category, durability, tagIds[], applicationMinTemp: RangeFilter, minApplicationTimeAt20: RangeFilter, sort).
- Новый `CoatingSystemSort` enum: DEFAULT, TITLE_ASC, TITLE_DESC, MIN_APPLICATION_TIME_ASC, MIN_APPLICATION_TIME_DESC.
- Новый `SearchCoatingSystemsForSuggestQuery` + Handler — лёгкий JSON для typeahead.

**Infrastructure**:
- Новый `CoatingSystemFinder` в `Coatings/Infrastructure/Search/`. JOIN трёх таблиц: `coating_system` (FROM) + `coating_system_search` (LEFT JOIN, всегда для FTS/min/max) + `coating_system_compliance` (EXISTS-подзапрос, только при compliance-фильтре, без дублей). Tags — JOIN через `coating_system_tag` с DISTINCT при tagIds-фильтре. FTS через существующий `PrefixTsQueryBuilder` + DQL функции (`TO_TSQUERY`, `TS_RANK_CD`, `@@`), язык `russian`. Sort DEFAULT: `TS_RANK_CD(...)` DESC когда q, `title` ASC иначе. Прочие sort — с `NULLS LAST` для legacy-строк.
- Новый `SuggestAction` для CoatingSystem: GET `/cabinet/coating/coating-system/suggest`, JSON `[{id, title}]`.
- Полная переписка `CoatingSystem/ListAction` — принимает q + все фасеты через URL, конвертирует часы→минуты для `minApplicationTimeAt20`, дёргает `SearchCoatingSystemsQuery` → hydrate через `findByIds` → `CoatingSystemDTOTransformer`. Рендерит полный шаблон или partial-fragment (при `?partial=1`).

**UI-каркас (partial'ы и макросы, переиспользуемо)**:
- `Templates/components/_search_toolbar.html.twig` — тонкий блок: поле q + submit + сброс. Параметры: `q_value`, `reset_url`, `placeholder`, `endpoint_typeahead` (async-typeahead включается при указанном endpoint). Используется в новом списке систем и в существующем `admin/coating/coating/index.html.twig` (заменяет inline-разметку строки).
- `Templates/components/facets.twig` — Twig-макросы:
  - `single_select_chip(label, name, options, current)` — chip-dropdown с submit-on-change.
  - `multi_select_chip(label, name, options, current[])` — то же, checkbox.
  - `tag_chip(name, tagifyEndpoint, currentTagIds)` — Tagify для тегов.
  - `range_chip(label, name_from, name_to, from, to, unit, presets)` — тонкая обёртка над существующим `components/range_filter_card.html.twig`.
- Существующие партиалы используем без правок: `components/list_page.html.twig`, `components/range_filter_card.html.twig`, `components/infinite_list.html.twig`.
- Stimulus-контроллеры без нового JS: `chip_facets`, `range_filter`, `async_typeahead`, `infinite_list`, `coating_tags`.

**UI-шаблон списка систем** (`Templates/cabinet/coating/coating_system/list.html.twig`, полная переписка по образцу `admin/coating/coating/index.html.twig`):

Порядок сверху вниз:
1. Toolbar `_search_toolbar` с typeahead → `/suggest`.
2. Chip-row (desktop):
   - Substrate (multi)
   - Standard (single) — при смене submit → зависимые Category/Durability обновляются.
   - Category (single) — опции ограничены выбранным Standard.
   - Durability (single) — опции ограничены выбранным Standard.
   - Tags (Tagify async).
   - Мин.Т нанесения (range, пресеты winter/standard/summer из существующего справочника покрытий).
   - Мин.время нанесения при 20°C (range, пресеты fast/standard/slow/very_slow; UI в часах, backend в минутах).
3. Sort-dropdown справа.
4. Mobile offcanvas — зеркало chip-row + отдельный «Все фильтры».
5. Список карточек.
6. Infinite scroll (`components/infinite_list.html.twig`, endpoint тот же ListAction c `?partial=1`).

**Карточка системы**:
- Title (ссылка на карточку).
- Substrate + treatment (описание/код).
- Layers-preview.
- Мин.Т нанесения (max по слоям), °C.
- Мин.время нанесения при 20°C — «~N ч» или «N дн».
- Compliance-badges (до 3 + «ещё K», клик — фильтр).
- Tags-badges.

**DTO CoatingSystemDTO** — источники данных:
- `compliance` заполняется **runtime через `ComplianceEvaluator`** (не из таблицы). `CoatingSystemDTOTransformer::fromEntity` вызывает `$system->complianceMatches($evaluator)`. Кэш-таблица только для поиска/фильтра.
- `minApplicationTimeAt20Minutes`, `maxLayerApplicationMinTemp` — runtime-методы агрегата (`$system->minApplicationTimeAt20Minutes()` и т.п.).

**Форма системы** (`Templates/cabinet/coating/coating_system/form.html.twig`) — добавляется поле «Теги» через Tagify. Маппер уже принимает `tagIds` (сделано в плане 1).

**Уборка `SearchByCompliance`** (мастер-каскад заменил отдельную страницу):
- Удалить `Coatings/Infrastructure/Controller/CoatingSystem/SearchByComplianceAction.php`.
- Удалить `Coatings/Infrastructure/Api/CoatingSystem/SearchByComplianceApiAction.php`.
- Удалить `Coatings/Application/UseCase/Query/SearchCoatingSystemsByCompliance/*`.
- Удалить `Templates/cabinet/coating/coating_system/search_by_compliance.html.twig`.
- Удалить их тесты (3 файла).
- Удалить `Coatings/Application/UseCase/Query/ListCoatingSystems/*` (заменён `SearchCoatingSystemsQuery`) + его тест.
- Роуты уйдут вместе с actions (`#[Route]`-атрибуты).

**Меню** (`Templates/base.html.twig`): пункт «Теги покрытий» → «Теги» (текст, URL прежний).

## Что НЕ входит в план 2

- Compare tray для систем + отдельная страница сравнения — следующий деплой после плана 2.
- Единый поиск (unified с вкладками покрытия/системы) — отдельная задача.
- Preset-ссылки на dashboard для систем — вне scope.
- `TagRenamed` event / инвалидация tsvector при переименовании тега — если понадобится, отдельным подшагом.

## Изменения по слоям

### Domain

Изменить:
- `Domain/Repository/CoatingSystemsFilter.php` — новые поля (см. выше).
- `Domain/Repository/CoatingSystemRepositoryInterface.php` — метод `findByIds(array $ids): array` (уже есть `findByLayerCoatingId`, `findAll`, `findById`).

Создать:
- `Domain/Repository/CoatingSystemSort.php` (enum: DEFAULT, TITLE_ASC, TITLE_DESC, MIN_APPLICATION_TIME_ASC, MIN_APPLICATION_TIME_DESC).

### Application

Создать:
- `Application/UseCase/Query/SearchCoatingSystems/SearchCoatingSystemsQuery.php` + `Handler.php`.
- `Application/UseCase/Query/SearchCoatingSystemsForSuggest/SearchCoatingSystemsForSuggestQuery.php` + `Handler.php`.

Изменить:
- `Application/DTO/CoatingSystems/CoatingSystemDTOTransformer.php` — compliance через evaluator, min/max через методы агрегата.

Удалить:
- `Application/UseCase/Query/ListCoatingSystems/` (папка).
- `Application/UseCase/Query/SearchCoatingSystemsByCompliance/` (папка).

### Infrastructure

Создать:
- `Infrastructure/Search/CoatingSystemFinder.php` — JOIN трёх таблиц, FTS через `PrefixTsQueryBuilder`, сорт, пагинация. Возвращает `SearchResult<string>` (id + total).
- `Infrastructure/Controller/CoatingSystem/SuggestAction.php` — JSON typeahead.

Изменить:
- `Infrastructure/Controller/CoatingSystem/ListAction.php` — полная переписка.
- `Infrastructure/Repository/CoatingSystemRepository.php` — `findByIds` с eager-load.
- `Infrastructure/Cache/CoatingSystemSearchCacheRepository.php` — SQL: колонка `min_application_time_at_20_minutes` (rename).

Удалить:
- `Infrastructure/Controller/CoatingSystem/SearchByComplianceAction.php`.
- `Infrastructure/Api/CoatingSystem/SearchByComplianceApiAction.php`.

### UI (Twig)

Создать:
- `Templates/components/_search_toolbar.html.twig`.
- `Templates/components/facets.twig` — макросы.

Изменить:
- `Templates/cabinet/coating/coating_system/list.html.twig` — полная переписка.
- `Templates/cabinet/coating/coating_system/form.html.twig` — поле «Теги».
- `Templates/admin/coating/coating/index.html.twig` — переход на `_search_toolbar`.
- `Templates/base.html.twig` — «Теги покрытий» → «Теги».

Удалить:
- `Templates/cabinet/coating/coating_system/search_by_compliance.html.twig`.

### БД (миграция)

- `Version20260802...` — RENAME колонки `min_building_time_at_20_minutes` → `min_application_time_at_20_minutes` + rename индекса `idx_css_min_building` → `idx_css_min_app_time`. Идемпотентно через `IF EXISTS`/`DO $$` для safe re-run.

## Порядок шагов реализации

Каждый шаг → показ пользователю → апрув → следующий.

1. **Rename `minBuildingTime` → `minApplicationTime`** — метод агрегата, колонка, индекс, миграция, все callers (SQL в cache repo, тесты, описание rebuild-команды). До всего прочего.
2. **Application: Query + Filter + Sort + Suggest** — `SearchCoatingSystemsQuery`, `SearchCoatingSystemsForSuggestQuery`, расширенный `CoatingSystemsFilter`, `CoatingSystemSort`. Функциональные тесты.
3. **Infrastructure: Finder + Repository** — `CoatingSystemFinder` (JOIN трёх таблиц), `findByIds`. Функциональные тесты Finder-а.
4. **DTO Transformer runtime** — `CoatingSystemDTOTransformer`: compliance через evaluator, min/max через методы агрегата. Обновить существующий тест transformer-а.
5. **UI-partial'ы `_search_toolbar` и `facets.twig`** — новые файлы. `admin/coating/coating/index.html.twig` переведён на toolbar. Визуальная проверка поиска покрытий (не должно ничего сломаться).
6. **UI-шаблон систем + `SuggestAction` + переписанный `ListAction`** — новый `list.html.twig`, новый action Suggest, переписанный ListAction. Функциональные тесты action-ов. Визуальная проверка (`yarn dev` для ассетов).
7. **Форма системы с полем «Теги»** — обновление `form.html.twig`. Функциональный тест что теги сохраняются через форму.
8. **Уборка `SearchByCompliance` + `ListCoatingSystems`** — удаление action-ов, Query, шаблона, тестов. Прогон всей suite чистый.
9. **Меню** — `base.html.twig`: «Теги покрытий» → «Теги».

## Error handling

- Ошибки формы (некорректный range, битый tag id) → `AppException` в domain → 422 + `<div class="alert alert-danger">`.
- Пустой результат — плейсхолдер «Ничего не найдено» (существующий из списка покрытий).
- Кэш-таблица разошлась с реальностью (баг event-subscriber-а) — фильтр может ложно исключить систему; карточка/DTO всегда показывают runtime-данные. Инвалидация вручную через `app:coating-system:rebuild-search-cache`.

## Тесты

**Unit** (`app/tests/Unit/Coatings/...`):
- `Domain/Repository/CoatingSystemSortTest` — enum: базовое покрытие всех кейсов.
- `Domain/Repository/CoatingSystemsFilterTest` — если фильтр обрастёт логикой (сейчас плоский struct, возможно не нужен).

**Functional** (`app/tests/Functional/Coatings/...`):
- Создать: `Application/UseCase/Query/SearchCoatingSystemsQueryHandlerTest` — q, каждый фасет отдельно, комбинации, все сорты, пагинация.
- Создать: `Application/UseCase/Query/SearchCoatingSystemsForSuggestQueryHandlerTest`.
- Создать: `Infrastructure/Search/CoatingSystemFinderTest` — прямой SQL-тест JOIN-а трёх таблиц.
- Создать: `Infrastructure/Controller/CoatingSystem/SuggestActionTest`.
- Обновить: `Infrastructure/Controller/CoatingSystem/ListActionTest` — q, чипы, infinite scroll partial.
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
- `app/src/Shared/Infrastructure/Database/Migrations/Version20260802*.php` — rename колонки.

**Изменить**:
- `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php` — rename `minBuildingTimeAt20Minutes` → `minApplicationTimeAt20Minutes`.
- `app/src/Coatings/Domain/Repository/CoatingSystemsFilter.php` — новые поля.
- `app/src/Coatings/Domain/Repository/CoatingSystemRepositoryInterface.php` — `findByIds`.
- `app/src/Coatings/Infrastructure/Repository/CoatingSystemRepository.php` — `findByIds`.
- `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTOTransformer.php` — runtime compliance/min/max.
- `app/src/Coatings/Infrastructure/Cache/CoatingSystemSearchCacheRepository.php` — SQL с новой колонкой.
- `app/src/Coatings/Infrastructure/Controller/CoatingSystem/ListAction.php` — полная переписка.
- `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/list.html.twig` — полная переписка.
- `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/form.html.twig` — поле «Теги».
- `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig` — переход на `_search_toolbar`.
- `app/src/Shared/Infrastructure/Templates/base.html.twig` — «Теги покрытий» → «Теги».
- Все тесты, ссылающиеся на старое имя `minBuildingTime`/`min_building_time`.

**Удалить**:
- `app/src/Coatings/Application/UseCase/Query/ListCoatingSystems/` (папка).
- `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystemsByCompliance/` (папка).
- `app/src/Coatings/Infrastructure/Controller/CoatingSystem/SearchByComplianceAction.php`.
- `app/src/Coatings/Infrastructure/Api/CoatingSystem/SearchByComplianceApiAction.php`.
- `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/search_by_compliance.html.twig`.
- Соответствующие функциональные тесты (3+).

## Cross-ref

- План 1: `plan-1-domain-and-cache-redesign.md` — определил домен + инфраструктуру кэша.
- Следующая задача (после плана 2): compare tray + страница сравнения систем — отдельный план.
