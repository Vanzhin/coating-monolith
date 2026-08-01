# Поиск систем покрытий — План 2: переключение чтения и UI

Второй из двух деплоев. Опирается на read-модель, наполненную планом 1 (`plan-1-projector.md`). Пользователь получает полноценный поиск систем: строка q (FTS), фасеты (substrate, compliance, tags, applicationMinTemp, sumMinRecoat20), сортировки, infinite scroll, typeahead. Отдельный `SearchByCompliance` вычищается.

## Зачем разделено

План 1 наполнил `coating_system_compliance` полями `search_tsvector`, `sum_min_recoat_20_minutes`, `max_application_min_temp` и связью с тегами. Rebuild-команда прогнала все системы. Теперь можно безопасно завести на эти поля новый Query и переключить UI — без риска что где-то данные ещё не подтянулись.

## Что входит в план 2

**Application**:
- Новый `SearchCoatingSystemsQuery` + Handler.
- Расширенный `CoatingSystemsFilter` (search, substrates[], standards[], categories[], durabilities[], tagIds[], applicationMinTemp: RangeFilter, sumMinRecoat20: RangeFilter, sort).
- Новый `CoatingSystemSort` enum (DEFAULT, TITLE_ASC, TITLE_DESC).
- Новый `SearchCoatingSystemsForSuggestQuery` + Handler — лёгкий JSON для typeahead.

**Infrastructure**:
- Новый `CoatingSystemFinder` в `Coatings/Infrastructure/Search/` по образцу `CoatingFinder`. Работает с read-моделью `coating_system_compliance` (наполнена планом 1, имя таблицы оставлено прежним). Использует существующий `App\Shared\Infrastructure\Database\FullTextSearch\PrefixTsQueryBuilder`.
- Новый `SuggestAction` для CoatingSystem (аналог существующего для покрытий).
- Полная переписка `CoatingSystem/ListAction` — теперь принимает q + все фасеты через URL, отдаёт полный шаблон или partial-fragment для infinite scroll.

**UI**:
- Новый partial `Templates/components/_search_toolbar.html.twig` — тонкий переиспользуемый блок: поле q + submit + сброс. Используется и в новом поиске систем, и в существующем поиске покрытий (`admin/coating/coating/index.html.twig` — заменить inline-разметку).
- Новый файл макросов `Templates/components/facets.twig`:
  - `single_select_chip(label, name, options, current)`.
  - `multi_select_chip(label, name, options, current[])`.
  - `tag_chip(name, tagifyEndpoint, currentTagIds)`.
- Полностью переписанный `Templates/cabinet/coating/coating_system/list.html.twig` — по образцу `admin/coating/coating/index.html.twig`, использует embed `components/list_page.html.twig`, включает `_search_toolbar`, `range_filter_card`, `infinite_list`, макросы `facets.twig`.
- Обновление формы `Templates/cabinet/coating/coating_system/form.html.twig` — добавить поле «Теги» через Tagify.
- Переиспользование существующих Stimulus-контроллеров: `chip_facets`, `range_filter`, `async_typeahead`, `infinite_list`, `coating_tags`. Новых JS-контроллеров не создаём.

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
- Линейная интерполяция мин.интервала перекрытия по толщине слоя — обсуждается и внедряется в рамках плана 1 (открытый пункт там).
- Preset-ссылки на dashboard для систем — вне скоупа обоих планов.

## Изменения по слоям

### Domain

Изменить:
- `Domain/Repository/CoatingSystemsFilter.php` — новые поля: `search: ?SearchQuery`, `substrates: Substrate[]`, `standards: ComplianceStandard[]`, `categories: IsoCorrosivityCategory[]`, `durabilities: IsoDurability[]`, `tagIds: string[]`, `applicationMinTemp: ?RangeFilter`, `sumMinRecoat20: ?RangeFilter`, `sort: CoatingSystemSort`.
- `Domain/Repository/CoatingSystemRepositoryInterface.php` — метод `findByIds(array $ids): array`.

Создать:
- `Domain/Repository/CoatingSystemSort.php` (enum: DEFAULT, TITLE_ASC, TITLE_DESC).

### Application

Создать:
- `Application/UseCase/Query/SearchCoatingSystems/SearchCoatingSystemsQuery.php` + `Handler.php`.
- `Application/UseCase/Query/SearchCoatingSystemsForSuggest/SearchCoatingSystemsForSuggestQuery.php` + `Handler.php` — лёгкий JSON (id + title).

Удалить:
- `Application/UseCase/Query/ListCoatingSystems/` (папка целиком).
- `Application/UseCase/Query/SearchCoatingSystemsByCompliance/` (папка целиком).

### Infrastructure

Создать:
- `Infrastructure/Search/CoatingSystemFinder.php` — QueryBuilder-конструктор фильтров, FTS через `PrefixTsQueryBuilder`, сортировки, пагинация. Возвращает `SearchResult<string>` (id + total).
- `Infrastructure/Controller/CoatingSystem/SuggestAction.php` — GET, JSON, использует `SearchCoatingSystemsForSuggestQuery`, роут `/cabinet/coating/coating-system/suggest`, имя `app_cabinet_coating_system_suggest`.

Изменить:
- `Infrastructure/Controller/CoatingSystem/ListAction.php` — переписать полностью: принимает q + все фасеты из URL, конвертирует часы→минуты для sumMinRecoat20, отдаёт `SearchCoatingSystemsQuery`, рендерит полный шаблон или partial-fragment (при `?partial=1`).
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
2. **Infrastructure: Finder + Repository** — `CoatingSystemFinder`, метод `findByIds` в репозитории. Тесты Finder.
3. **UI-partials: `_search_toolbar` и `facets.twig`** — новые файлы, `admin/coating/coating/index.html.twig` перешит на toolbar. Визуальная проверка в браузере поиска покрытий (не должно ничего сломаться).
4. **UI-шаблон списка систем + `SuggestAction` + переписанный `ListAction`** — новый `list.html.twig`, новый action Suggest, переписанный ListAction. Функциональные тесты обоих action-ов. Визуальная проверка в браузере.
5. **Форма системы с полем «Теги»** — обновление `form.html.twig`, `CoatingSystemMapper` (если ещё не сделано в плане 1). Функциональный тест.
6. **Уборка `SearchByCompliance`** — удалить action-ы, Query, шаблон, тесты, роуты. Прогон всех тестов — должно быть чисто.
7. **Меню**: `base.html.twig` — «Теги покрытий» → «Теги». Визуальная проверка.

## Error handling

- Ошибки формы (некорректный range, битый tag id) → `AppException` в domain → 422 + `<div class="alert alert-danger">` в шаблоне.
- Пустой результат — плейсхолдер «Ничего не найдено» (использовать существующий из списка покрытий).
- Ошибка проектора (наполнение уже сделано в плане 1) — логируется, не блокирует read-endpoint.

## Тесты

**Unit** (`app/tests/Unit/Coatings/...`):
- `Domain/Repository/CoatingSystemSortTest` — базовое покрытие enum-а.
- `Domain/Repository/CoatingSystemsFilterTest` — если фильтр обрастёт логикой (сейчас плоский struct, возможно не нужен).

**Functional** (`app/tests/Functional/Coatings/...`):
- Создать: `Application/UseCase/Query/SearchCoatingSystemsQueryHandlerTest` — q, каждый фасет, комбинации, сортировки, пагинация.
- Создать: `Application/UseCase/Query/SearchCoatingSystemsForSuggestQueryHandlerTest`.
- Создать: `Infrastructure/Controller/CoatingSystem/SuggestActionTest`.
- Обновить: `Infrastructure/Controller/CoatingSystem/ListActionTest` — q в URL, чипы, infinite scroll partial.
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

- План 1: `plan-1-projector.md` — наполнил read-модель, на которую опирается этот план.
- Следующая задача (после плана 2): compare tray + страница сравнения систем — отдельный план.
