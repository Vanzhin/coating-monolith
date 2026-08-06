# Причёсывание Coatings-экшенов — дизайн

Дата: 2026-08-05
Тип: чистый рефактор, один деплой (без миграций данных, без переключения чтения/записи)
Охват: bounded context `Coatings` (Coating, CoatingSystem; SurfaceTreatment/Tag — по факту)

## Проблема

Экшены `Coatings` разжирели и разъехались, нарушая собственные правила проекта
(«тонкий Action», «Mapper = чистый shape», «бизнес-правила — в домен»).

Диагноз по коду:

- **Read-side разжирел.** `Coating/ListAction` — 237 строк: ручной парсинг ~20
  query-параметров, конвертация единиц (часы/дни → минуты), 50 строк
  пресетов-констант, сборка `CoatingsFilter` на 15 аргументов, `try/catch` на
  доменную валидацию, ветка partial/full, render-payload на ~30 ключей.
- **Идиомы разъехались.** `CoatingSystem/ListAction` решает ту же задачу
  другими средствами: `readRange`/`rangeToHours` вместо `nullableInt`, свой
  способ конвертации единиц, свой парсинг коллекций. Два экшена — две идиомы
  для одной работы.
- **Write-side дублируется.** `enrichInputDataWithTitles` (Add) и
  `enrichWithTreatmentTitle` (Update) в `CoatingSystem` — почти одно и то же и
  уже разошлись (в Add суперсет, в Update огрызок). Паттерн
  `try/catch → re-render формы` скопирован в четырёх экшенах.
- **Бизнес-логика утекла в контроллер.** `CoatingSystem/UpdateAction::normalizeLayersInput`
  кидает доменные правила (`ТСП слоя должна быть положительной`,
  `некорректный id покрытия`) прямо из экшена. При этом инвариант толщины слоя
  **уже живёт в домене** — `CoatingSystemLayer::assertDftInCoatingRange` кидает
  `AppException`, если dft вне диапазона покрытия. Контроллер не добавляет
  правило, а дублирует его.
- **UI-конфиг как константы контроллера.** 50 строк пресетов диапазонов в
  `Coating/ListAction`.

## Выбранный подход: A — маперы + view-factory на компонент

Философия зафиксирована CLAUDE.md (тонкий Action, Mapper = чистый shape,
бизнес-правила в домен, builtin > кастом). Обсуждалась только степень
абстракции; выбран максимально «тонкий» вариант A (против «причесать по месту»
B и «native `#[MapQueryString]`» C — в проекте нет ни одного ValueResolver'а, а
кастомные куски всё равно потребовали бы места, расщепив логику).

### Целевой скелет экшена

- **List:** `$filter = $mapper->filterFromRequest($req)` → `$result = queryBus(...)`
  → `render($tpl, $viewFactory->build($req, $result))` + ветка partial. ~35 строк.
- **Add/Update:** собрать команду мапером → диспатч → redirect; на `\Exception`
  — re-render формы через общий rehydrator.

## Единицы дизайна

### 1. Query → domain Filter (shape в отдельном мапере)

- `Coatings/Infrastructure/Mapper/CoatingListRequestMapper::filterFromRequest(Request): CoatingsFilter`
  — забирает блок 98–169 из `Coating/ListAction` (search, StringCollection'ы,
  baseValues-enum-фильтр, RangeFilter'ы, конвертация UI→минуты). `AppException`
  при сборке фильтра по-прежнему кидает домен, ловит экшен.
- `Coatings/Infrastructure/Mapper/CoatingSystemListRequestMapper::filterFromRequest(Request): CoatingSystemsFilter`
  — забирает блок 36–74 из `CoatingSystem/ListAction` (включая `readRange`).

Почему отдельные классы, а не методы в существующих `CoatingMapper`/
`CoatingSystemMapper`: те про форму покрытия (create/update). «Фильтр списка» —
другая ответственность; смешивание растит God-object. Узкий `*ListRequestMapper`
тестируется round-trip'ом.

### 2. Общий хелпер query-параметров (унификация идиом)

- `Shared/Infrastructure/Helper/QueryParams`: `nullableInt`,
  `intRange(from, to, multiplier)`, `stringCollection(key, validator?)`,
  `enumList(...)`. Оба мапера используют его → `nullableInt` и `readRange`
  перестают быть двумя разными идиомами.

### 3. Пресеты — из контроллера в holder

- `Coatings/Infrastructure/View/CoatingRangePresets` (final, const-массивы
  APP_MIN_TEMP / VOLUME_SOLID / MIN_RECOAT_20 / MAX_RECOAT_20 + геттеры),
  co-located с view-factory. 50 строк уезжают из `Coating/ListAction`.

### 4. Render-payload → view-factory

- `Coatings/Infrastructure/View/CoatingListViewFactory::build(Request, GetPagedCoatingsQueryResult): array`
  и `CoatingSystemListViewFactory` — собирают ~30-ключевой payload (selectedTags,
  manufacturers, пресеты, sortOptions, preservedParams, echo-back значений
  формы). Шаблоны не трогаем — фабрика отдаёт тот же массив.

Спорный момент, согласован: фабрика переносит большой массив в тестируемое
место. В варианте B её бы не было и экшен остался «полутолстым». Выбран A —
фабрика остаётся.

### 5. Write-side: слои + enrich + re-render

- `CoatingSystemMapper::layersFromInput(array): list<{coatingId, dft}>` — чистый
  shape (int-cast, отбрасывание кривых строк), **без бизнес-throw'ов**.
  `normalizeLayersInput` из `UpdateAction` удаляется; инвариант толщины остаётся
  в `CoatingSystemLayer`. Предусловие: убедиться, что min диапазона DFT
  гарантированно > 0 (тогда правило `dft > 0` покрыто range-проверкой). Если
  нет — добавить инвариант `dft > 0` в `CoatingSystemLayer`, но правило всё равно
  живёт в домене, не в контроллере.
- `CoatingSystemFormRehydrator` (новый коллаборатор) — единый
  `enrichInputDataWithTitles`, заменяет разъехавшиеся `enrichInputDataWithTitles`
  (Add) / `enrichWithTreatmentTitle` (Update). Кладём **не** в мапер (мапер =
  чистый shape, а тут repo/queryBus-лукапы), а в отдельный сервис. Используется
  и Add, и Update.
- try/catch → re-render **не** абстрагируем в общий responder: Add и Update
  различаются (Update перечитывает свежий DTO + layersDto). Два вызова — не повод
  плодить. Дублирование убивается общим rehydrator'ом, остальное остаётся по месту.

## Тесты

- Unit: `CoatingListRequestMapper`, `CoatingSystemListRequestMapper` (query →
  Filter: конвертация единиц, enum-фильтр, инверсия RangeFilter), `QueryParams`,
  `CoatingSystemMapper::layersFromInput` (кривые строки отброшены).
- Функциональные list/add/update — остаются зелёными (гоняют реальный флоу).
- View-factory — лёгкий smoke на ключи payload.

## Инвентарь файлов

**Новые:**
- `Coatings/Infrastructure/Mapper/CoatingListRequestMapper`
- `Coatings/Infrastructure/Mapper/CoatingSystemListRequestMapper`
- `Coatings/Infrastructure/View/CoatingListViewFactory`
- `Coatings/Infrastructure/View/CoatingSystemListViewFactory`
- `Coatings/Infrastructure/View/CoatingRangePresets`
- `Coatings/Application/Service/CoatingSystemFormRehydrator`
- `Shared/Infrastructure/Helper/QueryParams`

**Худеют:**
- `Coating/{List,Add,Update}Action`
- `CoatingSystem/{List,Add,Update}Action`
- `CoatingSystemMapper` (+ `layersFromInput`)
- `CoatingSystemLayer` — только если min DFT не гарантирован > 0

**Аудит, трогаем по факту тех же антипаттернов:** `SurfaceTreatment/*`, `Tag/*`.

## Критерии успеха

- `Coating/ListAction` 237 → ~40 строк, `CoatingSystem/ListAction` 133 → ~35.
- Ноль бизнес-throw'ов в контроллерах Coatings.
- Одна идиома чтения query-параметров на оба list-экшена.
- Зелёные `tests/Unit/Coatings` + функциональные Coatings.

## Вне охвата

- Другие контексты (ChemicalResistance, Proposals, Users, Documents) — отдельно.
- `#[MapQueryString]`/ValueResolver как паттерн — сознательно не вводим.
- Переписывание Twig-шаблонов — фабрики отдают тот же payload.
