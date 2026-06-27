# Различение «нет данных» и «без ограничения» в recoating-интервалах

**Статус:** draft
**Дата:** 2026-06-27

## Цель

В форме покрытия для каждой точки `maxRecoatingInterval` (а в перспективе и `minRecoatingInterval`) семантически различить два разных пользовательских смысла, которые сейчас слиплись в одно значение:

- **«Без ограничения»** — производитель явно говорит «можно перекрывать когда угодно». Целевое, известное значение.
- **«Нет данных» (N/A)** — производитель просто не указал. Известие об отсутствии информации.

Сейчас оба попадают в форму как `0/0/0` и mapper выкидывает их через `dropZeroDurationPointsRecursively`. На compare-странице и в list-modal невозможно различить «производитель сказал — лимита нет» от «нет данных».

## Контекст

Что есть в коде:
- `App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature` — `final readonly class { int temperatureAt; int timeInMinutes; bool isCalculated; }`. Конструктор бьёт по `timeInMinutes <= 0`.
- `DryingTimeSeries::validatePointsConsistency` — физ-правило «при росте температуры время уменьшается».
- `DryingTimeSeries::fromArray` / `jsonSerialize` — JSON-хранение в jsonb-колонке.
- `CoatingMapper::dropZeroDurationPointsRecursively` — выкидывает точки с нулевой длительностью **только** из max-серии.
- `RecoatingTreeBuilder::build` — отказывается строить узел, у которого `default===[]` и есть `children!==[]`.
- Twig: `components/duration_input.html.twig`, `compare.html.twig:88`, `index.html.twig:267` — три места, где сейчас «без верхней границы» рендерится одинаково.

## Решения, зафиксированные на brainstorming

| Вопрос | Решение |
|---|---|
| Уровень различения | На уровне точки в серии (а не всего max-tree) |
| Min или только max | Оба (min тоже может быть N/A) |
| Связь min↔max в строке | Независимы (две отдельные ячейки) |
| Доменная модель | **`timeInMinutes: ?int`**, кодировка трёх состояний: `null` = N/A, `0` = unlimited, `>0` = duration. Без новых классов и enum'ов. |
| Миграция БД | Не нужна. `null` уже валиден в jsonb. Старые значения `>0` живут как были. |

## Семантика трёх состояний

```php
public function __construct(
    public int $temperatureAt,
    public ?int $timeInMinutes,        // null = N/A, 0 = unlimited, >0 = duration
    public bool $isCalculated = false,
) {
    if ($timeInMinutes !== null && $timeInMinutes < 0) {
        throw new AppException(...);
    }
}
```

| Значение | Смысл | Откуда |
|---|---|---|
| `>0` | Реальная длительность в минутах | Юзер ввёл duration |
| `0` | Без ограничения | Юзер выбрал «без ограничения» |
| `null` | Нет данных | Юзер выбрал «нет данных» или не заполнил |

Физ-правило в `DryingTimeSeries::validatePointsConsistency` сравнивает только точки с `timeInMinutes > 0`. Точки с `null`/`0` пропускаются (не имеют сравнимого числа).

## Что меняется

### 1. `TimeAtTemperature`

- `public int $timeInMinutes` → `public ?int $timeInMinutes`.
- Guard в конструкторе: `if ($timeInMinutes !== null && $timeInMinutes < 0) throw`.
- `isCalculated` остаётся `false` для `null`/`0` (нечего интерполировать).

### 2. `DryingTimeSeries`

- `validatePointsConsistency`: пропускать точки с `null`/`0`.
- `fromArray`: `isset($row['time_in_minutes']) ? (int) $row['time_in_minutes'] : null`.
- `jsonSerialize`: для каждой точки `time_in_minutes` пишется как есть; `null` сериализуется как `null`, `0` как `0`. Никакой подмены.
- `getPoint($t)` — алгоритм:
  1. **Точное совпадение** `point.temperatureAt === $t` → вернуть точку **как есть** (включая `null` и `0`). Пользователь специально пометил эту температуру; не подменяем.
  2. **Интерполяция** — `findBoundingPoints` ищет lower/upper **только среди точек с `timeInMinutes > 0`** (то есть Duration-точки). Точки `null`/`0` молча пропускаются.
     - Обе границы найдены → линейная интерполяция как сейчас, `isCalculated=true`.
     - Иначе (одна из границ не Duration или вне диапазона) → `null`.

  **Примеры** для серии `[+10°C → 24ч, +20°C → null, +30°C → 12ч, +40°C → 0]`:
  | Запрос | Результат | Почему |
  |---|---|---|
  | `getPoint(20)` | `null` (N/A) | Точное совпадение |
  | `getPoint(40)` | `0` (unlimited) | Точное совпадение |
  | `getPoint(15)` | интерпол(24ч, 12ч) | Между 10 и 30; null в 20 пропущен |
  | `getPoint(35)` | `null` | Upper точка 40 имеет `0` → пропущена → upper Duration нет |
  | `getPoint(5)` | `null` | Lower Duration нет |

  Обоснование: интерполировать между Duration и unlimited (`0` = «бесконечность») математически возможно, но физически бессмысленно. Между Duration и Unknown (null) — нет числа для одного из концов.
- `assertNotEmpty` остаётся — серия из 0 точек по-прежнему запрещена.

### 3. `CoatingMapper`

- **Удалить** `dropZeroDurationPointsRecursively` полностью и его вызов в `buildCoatingDtoFromInputData`. Все точки (включая `null` и `0`) доходят до Builder'а.
- `buildPointsFromInput`: научить читать новое поле `kind` из формы (`'duration'` / `'unlimited'` / `'unknown'`) и собирать соответственно:
  - `kind === 'duration'` → `$point->time_in_minutes = parseDurationInput($row)` (как сейчас); если результат `0` — это нелегальная duration (юзер ничего не ввёл), записываем как `null` (N/A).
  - `kind === 'unlimited'` → `$point->time_in_minutes = 0`.
  - `kind === 'unknown'` → `$point->time_in_minutes = null`.
  - legacy (нет `kind`): сохранить текущее поведение `parseDurationInput` для совместимости с возможными сторонними вызовами; результат `0` → `null` (а не `0`), чтобы не превращать «не ввёл» в «unlimited».
- `decomposeDurationForForm` дополняется обратной декомпозицией: по `timeInMinutes` вернуть `['kind' => ..., 'days' => ..., ...]` для подстановки в форму при edit.

### 4. `RecoatingTreeBuilder`

- Логика «default===[] + children!==[] → AppException» сохраняется. Точки с `null`/`0` — это **наличные** точки, default не считается пустым.
- Если **все** точки default'а это `null`/`unknown` (то есть пользователь не указал вообще ничего), а children непустые — формально default не пуст по массиву, но фактически информации нет. Не выбрасываем ошибку (юзер мог осознанно выбрать «нет данных»). Логика останется как сейчас — без специальной проверки.

### 5. Форма (`duration_input.html.twig`)

Сейчас одна кнопка, открывает modal с полями days/hours/minutes.

Меняется:
- Под кнопкой — три radio-кнопки (для max) или две (для min): «Длительность» / «Без ограничения» / «Нет данных».
- При выборе «Длительность» — modal как сейчас (days/hours/minutes).
- При «Без ограничения» — modal вообще не открывается, hidden field `kind=unlimited`, days/hours/minutes = 0.
- При «Нет данных» — `kind=unknown`, поля days/hours/minutes удаляются из формы (или ставятся пустыми).
- Внешний вид кнопки:
  - duration → `12 д 0 ч` (с цветом btn-outline-primary)
  - unlimited → `∞ без ограничения` (btn-outline-secondary)
  - unknown → `— нет данных` (btn-outline-secondary, text-muted)

Для min: radio из двух пунктов — «Длительность» / «Нет данных». «Без ограничения» в min семантически бессмысленна и в форме не предлагается. На уровне домена `timeInMinutes = 0` в min-серии — теоретически допустимое значение (validator его не отбросит), но форма пользователя в эту ловушку не пускает.

### 6. Compare-page и list-modal (Twig)

Сейчас:
```twig
{% if value is null %}<span class="text-muted">Без верхней границы</span>{% endif %}
```

Становится:
```twig
{% if value.time_in_minutes is null %}
    <span class="text-muted">— нет данных</span>
{% elseif value.time_in_minutes == 0 %}
    <span class="text-muted">∞ без ограничения</span>
{% else %}
    {{ value.time_in_minutes|duration_minutes }}
{% endif %}
```

Затронуты:
- `app/src/Shared/Infrastructure/Templates/admin/coating/coating/compare.html.twig`
- `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig` (preview-modal)
- `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_recoating_pair_table.html.twig` (партиал, рендерящий min/max в одной строке)

В `_recoating_pair_table.html.twig` сейчас условие на `maxPoint` или `point.is_calculated`. Добавляется третий путь для `time_in_minutes is null` / `== 0`.

### 7. ObjectComparator

Никаких изменений. `array_unique($values, SORT_REGULAR)` уже работает корректно: два `TimeAtTemperature(20, null)` равны, два `TimeAtTemperature(20, 0)` равны, разные значения не равны.

## Что НЕ входит

- **Не трогаем** `DryingTimeSeries` для `dryToTouch` / `fullCure`. Технически после правки `timeInMinutes: ?int` оно «разрешено» там, но валидация в `seriesFieldConstraints(required: true)` в `CoatingMapper` и UI этих полей не дают ввести null/0 → семантика сушки не меняется.
- **Не трогаем** nullable-default на уровне узла (`RecoatingIntervalTree::default`). «На уровне immersion нет общего лимита, а у immersion+ESI есть» — это отдельная задача про fall-through по дереву; здесь только про точки в серии.
- **Не трогаем** existing data в БД. Если у покрытия есть `max_recoating_interval IS NULL` — это по-прежнему «всё покрытие N/A» (по прошлому решению миграции). Если есть нодные default'ы с duration-точками — они продолжают работать как duration.
- **JS-validation** «строка не может быть полностью без данных при наличии других строк» — не делаем. Бэк всё пропустит, фронт не подсказывает.

## Тесты

### Unit

- `TimeAtTemperatureTest`: guard на `<0`; null OK; 0 OK; >0 OK.
- `DryingTimeSeriesTest`: 
  - Существующие тесты на физ-правило продолжают работать.
  - Новый тест: серия из mixed точек (Duration + Unlimited + Unknown) проходит, физ-правило применяется только к Duration.
  - Round-trip `jsonSerialize` → `fromArray`: точки с `null`/`0` корректно восстанавливаются.
  - **`getPoint` для mixed-серии** (явно проверить все 5 кейсов из таблицы выше): точное совпадение с null/0, интерполяция между двумя Duration через null, отказ интерполяции когда соседняя точка unlimited, отказ когда нет lower/upper Duration.
- `CoatingMapperTest`: 
  - Форма с `kind=unlimited` → `time_in_minutes=0`.
  - Форма с `kind=unknown` → `time_in_minutes=null`.
  - Форма с `kind=duration` + 0 длительность → `time_in_minutes=null` (а не 0).
  - Round-trip `decompose → build` для всех трёх kind.
- `RecoatingTreeBuilderTest`:
  - Default из одних Unknown-точек + есть children → строится без ошибки.
  - Default из mixed → строится.

### Functional

- `CreateCoatingCommandHandlerTest`: добавить сценарий «root.max все-точки=null, immersion.max.points[0]=12d, esi.max.points[0]=10d» — сейчас падает с AppException, после фикса должен сохраняться. После reload из БД — структура совпадает.
- `UpdateCoatingCommandHandlerTest`: тот же сценарий через UPDATE.

## Открытые вопросы

Нет.
