# Универсальный сервис сравнения объектов

**Статус:** draft  
**Дата:** 2026-06-24

## Цель

Завести один общий сервис сравнения, который не знает о доменных типах. Принимает класс + конфиг + N объектов, возвращает структурированный результат. Первый потребитель — сравнение покрытий. Следом — сравнение систем покрытий. Далее — любые сущности.

## Контекст

В админке нужна страница «сравнения» покрытий side-by-side: 2–4 покрытия в колонках, поля в строках, различия подсвечены. На list-странице юзер набирает покрытия в tray, потом открывает compare. На compare-странице может скрывать ненужные поля чекбоксами в сайдбаре.

Существующие правила проекта (см. CLAUDE.md):
- DTO — голые контейнеры, не носят UI-знания.
- Бэк отдаёт ошибку → фронт рендерит.
- Меньше кода — лучше.

## Архитектура

Два слоя:

**Слой 1 — общий type-agnostic сервис (`Shared/Application/Comparison/`).**  
Получает конфиг и объекты, отдаёт структуру строк с флагом «отличаются ли значения». Ничего про подписи/единицы/формат не знает.

**Слой 2 — per-type (controller + Twig-шаблон).**  
Знает какие поля важны для конкретной сущности, какие у строк подписи, как форматировать значения, как рендерить заголовки колонок. Кастомизация — здесь.

## Контракт общего сервиса

```php
namespace App\Shared\Application\Comparison;

final readonly class ComparisonConfig
{
    /** @param list<string> $fields пути PropertyAccess: 'title', 'dftRange.tdsDft' */
    public function __construct(public array $fields) {}
}

final readonly class ComparisonRow
{
    /** @param list<mixed> $values по объектам, в порядке входа */
    public function __construct(
        public string $field,
        public array $values,
        public bool $isDifferent,
    ) {}
}

final readonly class ComparisonResult
{
    /** @param list<ComparisonRow> $rows */
    public function __construct(public array $rows) {}
}

final readonly class ObjectComparator
{
    public function __construct(private PropertyAccessorInterface $propertyAccessor) {}

    public function compare(ComparisonConfig $config, object ...$objects): ComparisonResult;
}
```

**Поведение `compare()`:**
1. Минимум 2 объекта; иначе `AppException('Нужно минимум 2 объекта для сравнения.')`.
2. Все объекты одного класса; иначе `AppException` с указанием первого «чужого» класса.
3. Для каждого `$field` из конфига:
   - Достать значение из каждого объекта через `propertyAccessor->getValue($obj, $field)`.
   - Посчитать `isDifferent`: `count(array_unique($values, SORT_REGULAR)) > 1`.  
     `SORT_REGULAR` глубоко сравнивает VO/массивы (по значениям свойств), что нам и нужно: два структурно равных `DftRangeDTO` дадут `isDifferent=false`.
4. Сложить `list<ComparisonRow>` в `ComparisonResult`.

Сервис не знает про `Coating`, `CoatingSystem`, шаблоны, форматирование.

## Per-type слой: сравнение покрытий

### Controller

`app/src/Coatings/Infrastructure/Controller/Coating/CompareAction.php`

Маршрут: `GET /cabinet/coating/coating/compare?ids=<uuid>,<uuid>,...`

Логика:
1. Распарсить `ids` (макс. 4). Если < 2 — flash `'Выберите минимум 2 покрытия для сравнения.'`, redirect на list.
2. Загрузить `CoatingDTO[]` по списку id одним запросом — новый `GetCoatingsByIdsQuery` + handler в `Application/UseCase/Query/GetCoatingsByIds/` (цикл одиночных `GetCoatingQuery` дал бы N round-trip к Doctrine — невыгодно).
3. Собрать `ComparisonConfig` с заранее заданным списком полей:
   ```php
   new ComparisonConfig([
       'title', 'manufacturer.title', 'base',
       'volumeSolid', 'massDensity', 'pack', 'thinner', 'applicationMinTemp',
       'dftRange.min', 'dftRange.max', 'dftRange.tds_dft',
       'dryToTouch', 'fullCure',
       'minRecoatingInterval', 'maxRecoatingInterval',
   ])
   ```
4. `$result = $comparator->compare($config, ...$dtos);`
5. Render `admin/coating/coating/compare.html.twig` с `subjects` (исходные DTO для заголовков колонок) и `result`.

### Шаблон

`app/src/Shared/Infrastructure/Templates/admin/coating/coating/compare.html.twig`

Состав:
- Заголовки колонок — `subject.title` (название покрытия) с подзаголовком `subject.manufacturer.title`.
- Строки — `result.rows`, ключ строки — `row.field`, подпись через словарь `fieldLabels` (заведён прямо в шаблоне).
- Форматирование — Twig-фильтры + per-type макросы:
  - Скаляры — прямой вывод с единицами (`{{ row.values[i] }} %`, `{{ row.values[i] }} мкм`).
  - `dryToTouch` / `fullCure` (`list<DryingTimePointDTO>`) — компактный inline-список «при +20°C — 1ч 30м».
  - `minRecoatingInterval` / `maxRecoatingInterval` (`RecoatingIntervalTreeDTO`) — вынести существующий макрос `recoating_pair_table` из `index.html.twig` в shared partial `admin/coating/coating/_recoating_pair_table.html.twig`, использовать и там, и в compare-шаблоне.
- Подсветка — `<tr class="table-warning">` при `row.isDifferent`.
- Сайдбар с чекбоксами полей (по умолчанию все включены).

### Field-filter (UX)

Stimulus-контроллер `compare_filter_controller` на странице compare:
- Чекбоксы в сайдбаре, по одному на каждое поле из конфига.
- На change — toggle класса `.d-none` на `<tr data-field="...">`.
- Состояние сохраняется в localStorage по ключу `compare:fields:Coating`. При следующем визите — восстановить выбор. Sticky между визитами.

### Tray (выбор покрытий из списка)

Stimulus-контроллер `compare_tray_controller` на layout-уровне (cabinet/index.html.twig):
- Кнопка «+ в сравнение» рядом с каждым покрытием в списке.
- Состояние в localStorage по ключу `compare:Coating` (массив id).
- Sticky bar внизу страницы: «N выбрано · [Сравнить] · [Очистить]».
- Лимит 4: попытка добавить 5-й → alert «Можно сравнить максимум 4 покрытия».
- На клик «Сравнить» — `window.location.href = '/cabinet/coating/coating/compare?ids=' + ids.join(',')`.

## Файловая раскладка

**Новое:**
- `app/src/Shared/Application/Comparison/ObjectComparator.php`
- `app/src/Shared/Application/Comparison/ComparisonConfig.php`
- `app/src/Shared/Application/Comparison/ComparisonRow.php`
- `app/src/Shared/Application/Comparison/ComparisonResult.php`
- `app/src/Coatings/Infrastructure/Controller/Coating/CompareAction.php`
- `app/src/Coatings/Application/UseCase/Query/GetCoatingsByIds/GetCoatingsByIdsQuery.php`
- `app/src/Coatings/Application/UseCase/Query/GetCoatingsByIds/GetCoatingsByIdsQueryHandler.php`
- `app/src/Coatings/Application/UseCase/Query/GetCoatingsByIds/GetCoatingsByIdsQueryResult.php`
- `app/src/Shared/Infrastructure/Templates/admin/coating/coating/compare.html.twig`
- `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_recoating_pair_table.html.twig` (extracted from `index.html.twig`)
- `app/assets/controllers/compare_tray_controller.js`
- `app/assets/controllers/compare_filter_controller.js`

**Меняется:**
- `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig` — кнопка «+ в сравнение» на каждом покрытии + sticky compare-bar.
- `services.yaml` или config — регистрация `ObjectComparator` с `PropertyAccessorInterface`.

## Тесты

**Unit:** `app/tests/Unit/Shared/Application/Comparison/ObjectComparatorTest.php`
- 0/1 объект → AppException.
- 2 объекта разных классов → AppException.
- Все scalars равны → `isDifferent=false`.
- Scalars различаются → `isDifferent=true`.
- Два VO с одинаковыми значениями полей (разные instance) → `isDifferent=false` (SORT_REGULAR).
- Вложенный путь `'dftRange.tdsDft'` корректно достаётся.
- null значения корректно (`isDifferent=false` если все null, `true` если null vs значение).

**Functional:** `app/tests/Functional/Coatings/Infrastructure/Controller/Coating/CompareActionTest.php`
- 2 покрытия с пересекающимися/различающимися полями → 200 + ожидаемые подсветки в HTML.
- 1 покрытие → redirect + flash.
- Невалидный id → 404 или flash.

## Что НЕ входит в эту итерацию

- Сравнение `CoatingSystem`. Когда возьмём — добавится только `Coatings/.../CompareSystemAction` и `compare.html.twig` для систем; сервис не меняется.
- Export сравнения в PDF / CSV.
- Сравнение «совместимости» (можно ли красить друг поверх друга) — это другая задача, отдельный сервис на доменных правилах `Coating::canBeAppliedOnTopOf` / recoating-tree.
- Sharing compare-сессий между устройствами (localStorage достаточно для админки).
- Группировка полей в сайдбаре (если станет нужно — конфиг расширим до `list<FieldGroup>`).

## Открытые вопросы

Нет — все архитектурные решения зафиксированы в обсуждении.
