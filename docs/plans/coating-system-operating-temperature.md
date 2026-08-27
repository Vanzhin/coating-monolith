# План: фильтр систем по максимальной температуре эксплуатации

## Задача
Фильтр в поиске систем покрытий по **максимальной температуре эксплуатации** — «система держит ≥ T °C»,
зеркало температурного фасета покрытий. Химстойкость — отдельная следующая задача.

## Ключевые решения (согласованы)

1. **Источник правды — агрегат `Coating`.** Дефолт по основе живёт **в геттере**
   `Coating::getDryHeatExposure()`: если пределы сухого тепла null — возвращает дефолт по основе
   (continuous = peak = `defaultDryHeatMaxOperatingTemp`). Никаких доп. методов/полей: существующее
   поле DTO `dryHeatExposure` само несёт эффективное значение. Immersion — сырой, без дефолта.
   Побочка (принята заказчиком): форма покрытия предзаполняет дефолт и persist'ит его при сохранении —
   это заодно способ «заполнить через пользователя».
2. **Дефолт по основе, сухое тепло** (`CoatingBase::defaultDryHeatMaxOperatingTemp()`):
   AY/FEVE/PAS = 50 °C, остальные (AK/ESI/EP/PUR/PS) = 120 °C. **[СДЕЛАНО, зелёное]**
3. **В БД пределы покрытий не бэкфиллим.** Дефолт применяется read-time. Реальные значения заказчик
   введёт руками позже — сохранение покрытия само пересчитает снапшоты систем.
4. **Фильтр = зеркало фасета покрытий:** температура T + **среда** (сухое тепло / погружение,
   выбирает пользователь в фильтре) + чекбокс «с учётом пика». Только **верхняя** граница
   (как на фронте покрытий), нижнюю (`continuous_min`) не проверяем.
5. **continuous_max (постоянная) и peak_max (кратковременная) — разные параметры.** Снапшотим оба,
   т.к. min по слоям от peak ≠ min от continuous.
6. **Пересчёт через переиспользование Query `GetCoatingsByIds`** (не `repo` напрямую в обработчике):
   обработчик → id покрытий слоёв → `GetCoatingsByIds` → `CoatingDTO[]` с эффективными пределами → min.
7. **Фронт — переиспользовать компонент фасета покрытий**, если так легче/проще.

## Снапшот системы — 4 столбца в `coating_system_search`

Все = min по слоям (слабое звено), верхняя граница:

| Столбец | Значение | NULL когда |
|---|---|---|
| `dry_heat_continuous_max` | min(layer.coating.effectiveDryHeatContinuousMax) | пустая система |
| `dry_heat_peak_max` | min(layer.coating.effectiveDryHeatPeakMax) | пустая система |
| `immersion_continuous_max` | min(layer.coating.immersionExposure.continuousMax) | хоть у одного слоя нет immersion-пределов |
| `immersion_peak_max` | min(layer.coating.immersionExposure.peakMax ?? continuousMax) | хоть у одного слоя нет immersion-пределов |

Сухое тепло — всегда есть (дефолт по основе гарантирует). Immersion без дефолта: нет данных у слоя →
система в immersion-запрос не попадает (как `IS NOT NULL` у покрытий).

## Дефолт по основе в геттере (шаг 2, СДЕЛАНО)

```
getDryHeatExposure():
    dryHeatExposure ?? new ThermalExposureLimits(
        continuousMax: base.defaultDryHeatMaxOperatingTemp(),
        peakMax:       base.defaultDryHeatMaxOperatingTemp(),
    )
```
Задокументированные пределы (в т.ч. частичные) — как есть. Immersion эффективного дефолта не имеет,
читается сырым из `getImmersionExposure()`. Снапшот берёт с DTO: dry continuous = `dryHeatExposure.continuous_max`,
dry peak = `dryHeatExposure.peak_max ?? continuous_max`.

## Фильтр в finder

Ввод (зеркало покрытий): `thermalTemperature: ?int`, `thermalEnvironment: ?ThermalEnvironment`
(DRY_HEAT/IMMERSION), `thermalIncludingPeak: bool`. Активен, когда заданы и температура, и среда.

Столбец выбирается по паре (среда × пик):

| среда | пик | столбец |
|---|---|---|
| DRY_HEAT | нет | `dry_heat_continuous_max` |
| DRY_HEAT | да | `dry_heat_peak_max` |
| IMMERSION | нет | `immersion_continuous_max` |
| IMMERSION | да | `immersion_peak_max` |

SQL: `<столбец> IS NOT NULL AND <столбец> >= :thermTemp`.

## Шаги

### Шаг 1 — Дефолт на `CoatingBase` [СДЕЛАНО]
`CoatingBase::defaultDryHeatMaxOperatingTemp()` + `CoatingBaseTest`. Зелёное. БД не трогает.

### Шаг 2 — Дефолт по основе в геттере `Coating` (домен) [СДЕЛАНО]
- `Coating::getDryHeatExposure()` возвращает документированное `?? дефолт по основе` (continuous=peak).
- Юнит-тесты: документировано как есть / null → дефолт по основе (разные основы) / частичное не подменяем.
- Зелёное; весь unit-набор Coatings зелёный (регрессий нет).

### Шаг 3 — Проверка read-DTO (без изменений кода) [СДЕЛАНО]
- `CoatingDTO.dryHeatExposure` уже несёт эффективное значение (трансформер зовёт геттер).
- Тест `CoatingDTOTransformer`: покрытие без dry-heat пределов → DTO несёт дефолт по основе (EP→120/120),
  immersion остаётся null. Зелёное.

### Шаг 4 — 4 столбца снапшота + миграция [СДЕЛАНО]
- `Version20260827120000`: 4 столбца `INT NULL` в `coating_system_search` + 4 btree-индекса,
  идемпотентно (`ADD COLUMN IF NOT EXISTS`). Накатана и проверена (столбцы на месте). Бэкфилла нет.

### Шаг 5 — Пересчёт снапшота через `GetCoatingsByIds` [СДЕЛАНО]
- `Application/Service/OperatingTemperatureSnapshot` (VO, 4 nullable int) +
  `CoatingSystemOperatingTemperatureCalculator(QueryBusInterface)`: система → id покрытий слоёв →
  `GetCoatingsByIdsQuery` → `CoatingDTO[]` → 4 min (слабое звено с проброской null).
- `CoatingSystemSearchCacheRepository::upsert($system, $temps)` пишет 4 столбца.
- Провязано в оба обработчика + `RebuildCoatingSystemSearchCacheCommand`.
- Функциональный тест калькулятора (дефолт по основе, слабое звено, immersion min, immersion null) —
  зелёный; кэш-тест с проверкой 4 столбцов — зелёный; весь функциональный Coatings — 209 тестов зелёные.
- Миграция накатана на dev и test БД.

### Шаг 6 — Фильтр: finder + filter-DTO + mapper + view [СДЕЛАНО]
- `CoatingSystemsFilter`: + `thermalTemperature`/`thermalEnvironment`/`thermalIncludingPeak` + `hasThermalFacet()`.
- `CoatingSystemFinder::applyThermalExposureFacet`: столбец по (среда × пик), `IS NOT NULL AND >= :t`.
- `CoatingSystemListRequestMapper` (парсинг `thermTemp`/`thermEnv`/`thermPeak`) + `CoatingSystemListViewFactory` (echo-back).
- 3 функциональных теста finder (сухое тепло / immersion-исключение / переключатель пика) — зелёные.
- Побочно: починена предсуществующая флаки `SystemDocumentsReadModelTest::test_search_query_sets_document_count`
  (детерминированный upsert кэша в setUp, не полагаемся на событие onFlush). Coatings-only 10x зелёные.
  Остаётся отдельная предсуществующая кросс-контекстная флаки (tearDown FK + документные тесты Certificates) —
  не связана с фичей, не трогаем.

### Шаг 7 — UI (переиспользовать компонент покрытий) [СДЕЛАНО]
- Разметка температурного фасета скопирована 1-в-1 из списка покрытий (`admin/coating/coating/index.html.twig`)
  в список систем (`cabinet/coating/coating_system/list.html.twig`): chip-дропдаун, мобильный offcanvas
  `#chipFilterThermal`, секция в шторке «Все фильтры», активный чип-сброс, счётчик активных фильтров,
  empty-state. Параметры `thermTemp`/`thermEnv`/`thermPeak`, форма `systemListForm`, роут системного списка.
- `yarn dev` пересобран. Контроллерные тесты (`ListActionTest`): фасет рендерится + активный фильтр рисует
  чип-сброс — зелёные (13 тестов).

### Шаг 8 — Вывод макс. Т эксплуатации в карточке и модалке [СДЕЛАНО]
- `CoatingSystem::maxDryHeatContinuousOperatingTemp(): ?int` — доменный метод (min по слоям, слабое звено,
  дефолт по основе), по образцу `maxLayerApplicationMinTemp`.
- `CoatingSystemDTO` + `CoatingSystemDTOTransformer` (один трансформер кормит и список, и превью).
- Шаблоны `_list_cards.html.twig` + `_coating_system_preview.html.twig`: «Макс. Т эксплуатации: N °C»
  рядом с «Мин. Т нанесения». Показываем сухое тепло / continuous.
- Тесты: домен 3, трансформер 1 — зелёные; ListActionTest рендерит; функц. Coatings 214 зелёные.

## Тесты
- Unit: `CoatingBase`, `Coating::getDryHeatExposure`, `CoatingSystem::maxDryHeatContinuousOperatingTemp`,
  `CoatingDTOTransformer`, `CoatingSystemDTOTransformer`.
- Functional (реальная БД): калькулятор снапшота, finder-фильтр, ListAction рендер.

## Деплой
Всё аддитивно — один деплой. После выката — `app:coating-system:rebuild-search-cache` для заполнения
новых столбцов существующих систем.
