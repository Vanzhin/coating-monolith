# Coating Aggregate Refactor — VO для технических характеристик

**Дата:** 2026-06-06
**Статус:** Дизайн
**Контекст:** Подготовка модели Coating перед добавлением полнотекстового поиска (PG FTS).

## Проблема

Текущий агрегат `App\Coatings\Domain\Aggregate\Coating\Coating` — частично анемичный:

- На каждое поле есть публичный `setXxx` — внешний код может изменить любое поле в любой момент, бизнес-семантика операций не выражена.
- Валидация неконсистентна: где-то `AssertService`, где-то `throw new \Exception(...)`, два поля (`applicationMinTemp`, `maxRecoatingInterval`) валидации не имеют.
- Нет кросс-полевых инвариантов: можно создать `dryToTouch > fullCure` или `minRecoatingInterval > maxRecoatingInterval`.
- `setTags(Collection)` позволяет внешнему коду подменить всю коллекцию тегов.
- `dryToTouch` и `fullCure` хранятся как `float` — одно значение «при подразумеваемой температуре». Реально это **функция от температуры**: при +5°C — одно время, при +20°C — другое. Эта структура нужна для адекватной модели данных.

`UpdateCoatingCommandHandler` усугубляет анемичность: проходит по каждому полю DTO через `if` и вызывает соответствующий сеттер. Бизнес-намерения теряются в анализе DTO.

## Цель

1. Перевести числовые группы и температурно-зависимые величины в типизированные Value Objects с инвариантами.
2. Заменить публичные сеттеры на бизнес-методы. Каждое изменение проходит через `assertInvariants()`.
3. Убрать неконсистентную валидацию, перенести её в VO или базовые проверки агрегата.

После этого блока разблокируется работа над FTS + trgm-fallback.

## Out of scope

Следующее **не** входит в этот PR (отдельные итерации):

- Полнотекстовый поиск (`search_vector`, GIN, websearch_to_tsquery, trgm).
- Новое поле `applicationArea` / `purpose` или иные новые домены.
- Температурно-зависимый `recoatingInterval` — остаётся как `PositiveNumberRange` без температуры. По решению пользователя.
- Семантический поиск (этап Б), pgvector.
- Расширение профилей на другие параметры покрытия (давление, plant capacity и т.п.) — каркас Series готовится к этому, но конкретных подклассов сейчас один.

## Архитектура

### 1. Уже сделано

`DftRange` VO с `tdsDft` внутри, через композицию с `PositiveNumberRange` и `ThicknessType` enum. Инвариант `min <= tds <= max` реализован через `range->isWithin($tdsDft)` в конструкторе.

Поля `Coating::tdsDft/minDft/maxDft` заменены на одно `private DftRange $dftRange`.

### 2. Series + SeriesPoint (общий каркас, Shared)

Абстрактный каркас для хранения точек «ключ → значение» с настраиваемой валидацией. Аналогичен паттерну `NumberRange` (abstract + hook `validate()`).

**`src/Shared/Domain/Aggregate/ValueObject/SeriesPoint.php`**

```php
interface SeriesPoint extends \JsonSerializable
{
    public function getKey(): int|float;
    public function getValue(): int|float;
    public function isCalculated(): bool;
}
```

**`src/Shared/Domain/Aggregate/ValueObject/Series.php`**

```php
abstract readonly class Series implements \JsonSerializable
{
    /** @var SeriesPoint[] отсортирован по getKey() возрастающе, ключи уникальны */
    public array $points;

    public function __construct(array $points)
    {
        if (count($points) === 0) {
            throw new AppException('Профиль не может быть пустым.');
        }
        foreach ($points as $i => $p) {
            if (!$p instanceof SeriesPoint) {
                throw new AppException("Элемент {$i} не реализует SeriesPoint.");
            }
        }
        usort($points, fn(SeriesPoint $a, SeriesPoint $b) => $a->getKey() <=> $b->getKey());
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            if ($points[$i]->getKey() === $points[$i - 1]->getKey()) {
                throw new AppException(sprintf('Дублирующийся ключ %s.', $points[$i]->getKey()));
            }
        }
        $this->points = array_values($points);

        $this->validate();
    }

    abstract protected function validate(): void;

    /**
     * Создаёт типизированную точку конкретного наследника.
     * Используется для возврата расчётных точек.
     */
    abstract protected function createPoint(int|float $key, int|float $value, bool $isCalculated): SeriesPoint;

    /**
     * Возвращает точную или расчётную (линейная интерполяция) точку.
     * Возвращает null, если запрошенный ключ вне известного диапазона.
     */
    public function getPoint(int|float $key): ?SeriesPoint
    {
        foreach ($this->points as $p) {
            if ($p->getKey() == $key) {
                return $p;
            }
        }
        $bounds = $this->findBoundingPoints($key);
        if ($bounds === null) {
            return null;
        }
        [$lower, $upper] = $bounds;
        $value = $this->linearInterpolate($key, $lower, $upper);
        return $this->createPoint($key, $value, isCalculated: true);
    }

    /**
     * Возвращает ассоциативный массив [key => ?SeriesPoint] для всех ключей
     * из [from, to] с шагом step. Все запрошенные ключи присутствуют в результате;
     * вне-диапазонные имеют значение null.
     *
     * Замечание: для int ключей PHP сохраняет их как int-индексы.
     * Для float ключей PHP приводит ключи к int с потерей точности — поэтому
     * Series с float ключами требует отдельной адаптации (вне scope).
     *
     * @return array<int|float, ?SeriesPoint>
     */
    public function getRange(int|float $from, int|float $to, int|float $step): array
    {
        if ($step <= 0) {
            throw new AppException('Шаг должен быть положительным.');
        }
        if ($from > $to) {
            throw new AppException('from должно быть <= to.');
        }
        $result = [];
        for ($k = $from; $k <= $to; $k += $step) {
            $result[$k] = $this->getPoint($k);
        }
        return $result;
    }

    private function findBoundingPoints(int|float $key): ?array
    {
        $first = $this->points[0];
        $last = $this->points[count($this->points) - 1];
        if ($key < $first->getKey() || $key > $last->getKey()) {
            return null;
        }
        for ($i = 1, $n = count($this->points); $i < $n; $i++) {
            if ($this->points[$i]->getKey() >= $key) {
                return [$this->points[$i - 1], $this->points[$i]];
            }
        }
        return null;
    }

    private function linearInterpolate(int|float $key, SeriesPoint $lower, SeriesPoint $upper): int|float
    {
        $k1 = $lower->getKey();
        $k2 = $upper->getKey();
        $v1 = $lower->getValue();
        $v2 = $upper->getValue();
        return $v1 + ($v2 - $v1) * ($key - $k1) / ($k2 - $k1);
    }

    public function jsonSerialize(): array
    {
        return array_map(fn(SeriesPoint $p) => $p->jsonSerialize(), $this->points);
    }
}
```

**Поведение:**

- `getPoint($key): ?SeriesPoint` — возвращает точную точку (если есть) или **расчётную** через **линейную интерполяцию** между двумя ближайшими известными точками. Расчётная точка имеет `isCalculated() === true`. Если ключ вне диапазона известных точек — возвращает `null`.
- `getRange($from, $to, $step): array<int|float, ?SeriesPoint>` — ассоциативный массив, где **все** запрошенные ключи присутствуют. Для ключей вне диапазона значение — `null`. Внутри диапазона — точная или расчётная точка.
- `validate()` — hook для конкретных правил наследника.
- `createPoint()` — hook для типизированной фабрики (наследник знает, как создать свой `SeriesPoint`).

**Трансформации (immutable — возвращают новый Series):**

```php
/**
 * Возвращает новый Series, применив функцию к каждому значению.
 * Ключи и флаги isCalculated сохраняются.
 * Если результат нарушает инварианты (монотонность, диапазоны точек) — бросает исключение
 * из конструктора нового профиля.
 *
 * @param callable(int|float $value, int|float $key): int|float $fn
 */
public function map(callable $fn): static
{
    $newPoints = [];
    foreach ($this->points as $p) {
        $newValue = $fn($p->getValue(), $p->getKey());
        $newPoints[] = $this->createPoint($p->getKey(), $newValue, $p->isCalculated());
    }
    return new static($newPoints);
}

/**
 * Умножает все значения на коэффициент. Частный случай map.
 */
public function multiply(float $factor): static
{
    return $this->map(fn(int|float $v) => $v * $factor);
}
```

Использование при составлении спецификации покрытий (CoatingSystem или подобное):

```php
// +20% запас по времени высыхания
$adjusted = $coating->getDryToTouch()->multiply(1.2);

// Кастомная адаптация: при низких температурах ещё больший запас
$adjusted = $coating->getDryToTouch()->map(
    fn(float $minutes, int $celsius) => $celsius < 10 ? $minutes * 1.5 : $minutes * 1.1
);

// Цепочка: умножение + ещё одна трансформация
$result = $coating->getFullCure()
    ->multiply(1.1)
    ->map(fn($v) => max($v, 60)); // нижний клипп на 60 мин
```

**Важно:**
- Трансформация **не модифицирует** исходный профиль — возвращается новый.
- Все валидации (минимум 1, уникальные ключи, монотонность из `validate()`) выполняются для **результата**. Если callable вернёт значения, нарушающие монотонность — конструктор результата бросит исключение.
- `multiply(0)` или `multiply(-1)` могут привести к нарушению инварианта `minutes >= 0` в `TimeAtTemperature` — бросится исключение. Это намеренно: коэффициенты должны быть осмысленными.

### 3. TimeAtTemperature + DryingTimeSeries (Coatings)

**`src/Coatings/Domain/Aggregate/Coating/TimeAtTemperature.php`**

```php
final readonly class TimeAtTemperature implements SeriesPoint
{
    public function __construct(
        public int $celsius,
        public float $minutes,
        public bool $isCalculated = false,
    ) {
        if ($minutes < 0) {
            throw new AppException('Время не может быть отрицательным.');
        }
        // celsius может быть отрицательной — допустимо
    }

    public function getKey(): int { return $this->celsius; }
    public function getValue(): float { return $this->minutes; }
    public function isCalculated(): bool { return $this->isCalculated; }

    public function jsonSerialize(): array
    {
        return ['celsius' => $this->celsius, 'minutes' => $this->minutes];
    }
}
```

**`src/Coatings/Domain/Aggregate/Coating/DryingTimeSeries.php`**

```php
final readonly class DryingTimeSeries extends Series
{
    public function __construct(array $points)
    {
        foreach ($points as $p) {
            if (!$p instanceof TimeAtTemperature) {
                throw new AppException('DryingTimeSeries принимает только TimeAtTemperature.');
            }
        }
        parent::__construct($points);
    }

    protected function validate(): void
    {
        // Физический инвариант: чем выше температура, тем меньше (или равно) время.
        // Монотонность нестрогая — допускает округлённые значения.
        for ($i = 1, $n = count($this->points); $i < $n; $i++) {
            /** @var TimeAtTemperature $cur */
            $cur = $this->points[$i];
            /** @var TimeAtTemperature $prev */
            $prev = $this->points[$i - 1];
            if ($cur->minutes > $prev->minutes) {
                throw new AppException(sprintf(
                    'Нарушение монотонности: при %d°C %g мин > чем при %d°C %g мин.',
                    $cur->celsius, $cur->minutes, $prev->celsius, $prev->minutes
                ));
            }
        }
    }

    protected function createPoint(int|float $key, int|float $value, bool $isCalculated): SeriesPoint
    {
        return new TimeAtTemperature((int) $key, (float) $value, $isCalculated);
    }
}
```

Один и тот же класс `DryingTimeSeries` используется и для `dryToTouch`, и для `fullCure` — правила одинаковые.

### 4. Хранение в БД

JSONB-колонка для каждого профиля. Простой и атомарный путь. Без отдельной таблицы и Doctrine-ассоциаций.

```sql
ALTER TABLE coatings_coating
    ALTER COLUMN dry_to_touch TYPE JSONB
    USING jsonb_build_array(jsonb_build_object('celsius', 20, 'minutes', dry_to_touch::float));

ALTER TABLE coatings_coating
    ALTER COLUMN full_cure TYPE JSONB
    USING jsonb_build_array(jsonb_build_object('celsius', 20, 'minutes', full_cure::float));
```

Старые float-значения мигрируются как одна точка при +20°C — стандарт техкарт. Это безопасный дефолт: если в существующих данных значение было «при подразумеваемой температуре», то +20°C — самое вероятное значение этой подразумеваемой температуры.

Формат хранения:

```json
[{"celsius": 5, "minutes": 30}, {"celsius": 20, "minutes": 10}, {"celsius": 30, "minutes": 5}]
```

### 5. DBAL Type

Custom Doctrine Type для прозрачной (де)сериализации `DryingTimeSeries <-> JSONB`.

**`src/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesType.php`**

```php
final class DryingTimeSeriesType extends JsonType
{
    public const NAME = 'drying_time_series';

    public function getName(): string { return self::NAME; }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) return null;
        if (!$value instanceof DryingTimeSeries) {
            throw new \InvalidArgumentException('Expected DryingTimeSeries');
        }
        return parent::convertToDatabaseValue($value->jsonSerialize(), $platform);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?DryingTimeSeries
    {
        if ($value === null) return null;
        $raw = parent::convertToPHPValue($value, $platform);
        $points = array_map(
            fn(array $p) => new TimeAtTemperature((int) $p['celsius'], (float) $p['minutes']),
            $raw
        );
        return new DryingTimeSeries($points);
    }
}
```

Регистрация в `config/packages/doctrine.yaml` под именем `drying_time_series`.

### 6. Coating: финальное состояние

**Поля:**

```php
private readonly string $id;
private string $title;
private string $description;
private int $volumeSolid;
private float $massDensity;
private DftRange $dftRange;                       // уже сделано
private int $applicationMinTemp;
private DryingTimeSeries $dryToTouch;            // было: float
private float $minRecoatingInterval;
private float $maxRecoatingInterval;
private DryingTimeSeries $fullCure;              // было: float
private Manufacturer $manufacturer;
private CoatingSpecification $specification;
private float $pack;
private ?string $thinner;
private Collection $tags;
```

**Конструктор** (14 параметров):

```php
public function __construct(
    string $title,
    string $description,
    int $volumeSolid,
    float $massDensity,
    DftRange $dftRange,
    int $applicationMinTemp,
    DryingTimeSeries $dryToTouch,
    float $minRecoatingInterval,
    float $maxRecoatingInterval,
    DryingTimeSeries $fullCure,
    float $pack,
    ?string $thinner,
    Manufacturer $manufacturer,
    CoatingSpecification $specification,
)
```

Конструктор делает прямые присвоения через приватные сеттеры (которые делают per-field валидацию), в конце вызывает `assertInvariants()`.

**Публичные бизнес-методы** (заменяют сеттеры):

| Метод | Семантика |
|---|---|
| `changeTitle(string)` | переименование |
| `changeDescription(string)` | смена описания |
| `changeThinner(?string)` | смена растворителя |
| `changeVolumeSolid(int)` | смена сухого остатка |
| `changeMassDensity(float)` | смена плотности |
| `changeDftRange(DftRange)` | смена диапазона сухой плёнки |
| `changeApplicationMinTemp(int)` | смена минимальной температуры нанесения |
| `changeDryToTouch(DryingTimeSeries)` | смена профиля высыхания на отлип |
| `changeMinRecoatingInterval(float)` | смена минимального интервала перекрытия |
| `changeMaxRecoatingInterval(float)` | смена максимального интервала перекрытия |
| `changeFullCure(DryingTimeSeries)` | смена профиля полного отверждения |
| `changePack(float)` | смена упаковки |
| `changeManufacturer(Manufacturer)` | смена производителя |
| `addTag(CoatingTag)` | добавление тега |
| `removeTag(CoatingTag)` | удаление тега |
| `replaceTags(CoatingTag[])` | полная замена набора тегов |

Каждый `change*` метод выполняет:
1. Per-field валидацию через приватный сеттер.
2. `assertInvariants()` в конце.

**`assertInvariants()` (public):**

```php
public function assertInvariants(): void
{
    if ($this->minRecoatingInterval > $this->maxRecoatingInterval) {
        throw new AppException(sprintf(
            'Минимальный интервал перекрытия (%g) не может превышать максимальный (%g).',
            $this->minRecoatingInterval,
            $this->maxRecoatingInterval
        ));
    }
}
```

Кросс-инвариант `dryToTouch <= fullCure` на уровне Coating **не вводим** — он усложняет API (требовал бы одинаковых температур в обоих профилях). Физически почти всегда верен, но в текущей модели его не было — не вводим лишние ограничения. Внутри каждого профиля своя монотонность проверяется.

**Удалённые методы:**

- `setTitle/setDescription/setVolumeSolid/setMassDensity/setApplicationMinTemp/setDryToTouch/setMinRecoatingInterval/setMaxRecoatingInterval/setFullCure/setPack/setThinner/setManufacturer/setTags` — публичные сеттеры.
- `setTags(Collection)` удаляется без замены — внешний код всегда работает через `addTag/removeTag/replaceTags`.

`new \Exception(...)` в `setVolumeSolid` и `setPack` заменяется на `AppException` с понятными сообщениями.

### 7. ORM маппинг

`Coating.Coating.orm.xml` обновляется:

- `dry_to_touch` — `<field name="dryToTouch" column="dry_to_touch" type="drying_time_series"/>` (custom DBAL type)
- `full_cure` — аналогично
- `dft_range` — embedded mapping (детали — отдельный пункт миграционного плана; здесь оставлено как технический момент, разрешение которого можно отложить до реализации)

### 8. Влияние на callsites

| Файл | Изменение |
|---|---|
| `Coating.php` | Полная переработка (см. выше) |
| `CoatingFactory.php` | Параметры: `DryingTimeSeries`, `DftRange` вместо float/int групп. Сигнатура `create()` меняется. |
| `Coating.Coating.orm.xml` | `dft_range` embedded, `dry_to_touch`/`full_cure` custom type |
| `CoatingMapper.php` | Конструирование `DftRange` и `DryingTimeSeries` из DTO/массивов |
| `CoatingDTO.php` | `dryToTouch: ?array`, `fullCure: ?array` (массив структур `{celsius, minutes}`). `tdsDft/minDft/maxDft` → группа dft с такой же структурой через DTO. |
| `CoatingDTOTransformer.php` | Преобразование `Coating` → DTO с массивами для профилей |
| `UpdateCoatingCommandHandler.php` | Заменить `set*` на `change*`. Для профилей — собрать `DryingTimeSeries` из массива в DTO. |
| `AddAction.php` / `UpdateAction.php` | Передача профилей в DTO. |
| Symfony Forms (если есть отдельный тип) | Поля dryToTouch/fullCure — таблица «+/− строка» с парой полей `celsius` / `minutes`. |
| Миграция БД | `dryToTouch FLOAT → JSONB`, `fullCure FLOAT → JSONB`, `INTEGER tds_dft/min_dft/max_dft` → embedded mapping (детали в плане). |

### 9. Сценарии использования

**Создание покрытия с одной точкой профиля** (минимальный кейс):

```php
$dryToTouch = new DryingTimeSeries([
    new TimeAtTemperature(20, 10.0),
]);
$fullCure = new DryingTimeSeries([
    new TimeAtTemperature(20, 60.0),
]);
$coating = new Coating(..., dftRange: $dftRange, dryToTouch: $dryToTouch, fullCure: $fullCure, ...);
```

**Создание с несколькими точками:**

```php
$dryToTouch = new DryingTimeSeries([
    new TimeAtTemperature(5, 30.0),
    new TimeAtTemperature(20, 10.0),
    new TimeAtTemperature(30, 5.0),
]);
```

**Получение значения для произвольной температуры:**

```php
$point = $coating->getDryToTouch()->getPoint(25);
echo $point->minutes;        // 7.5 (линейная интерполяция между 20→10 и 30→5)
echo $point->isCalculated;   // true

$exact = $coating->getDryToTouch()->getPoint(20);
echo $exact->isCalculated;   // false

$outOfRange = $coating->getDryToTouch()->getPoint(50);  // если профиль [5..30]
// null
```

**Получение последовательности для UI-таблицы:**

```php
// профиль: [5→30, 20→10, 30→5]
$range = $coating->getDryToTouch()->getRange(10, 50, 10);
// [
//   10 => TimeAtTemperature(10, 21.7, isCalculated: true),   // интерполяция
//   20 => TimeAtTemperature(20, 10.0, isCalculated: false),  // точная
//   30 => TimeAtTemperature(30, 5.0,  isCalculated: false),  // точная
//   40 => null,                                              // вне диапазона
//   50 => null,                                              // вне диапазона
// ]
```

## Решения по умолчанию

| Решение | Выбор | Обоснование |
|---|---|---|
| Где живёт `Series` и `SeriesPoint` | Shared | Общий каркас, переиспользуется. Аналогично `NumberRange`. |
| Тип точки для drying | `TimeAtTemperature(int celsius, float minutes)` | Целая температура — естественно. Float минуты — для гибкости. |
| Один `DryingTimeSeries` для обоих свойств | Да | Правила одинаковы. |
| Минимум точек в профиле | 1 | Не блокируем покрытия с одной точкой в техкарте. |
| Монотонность | Нестрогая (`>=`) | Допускает округлённые равные значения. |
| Сортировка | Автоматически в конструкторе | Нормализация ввода. |
| Уникальность ключей | Обязательна | Нет смысла в двух значениях при одной температуре. |
| Интерполяция | Линейная между двумя ближайшими | Математически корректное обобщение «среднего». |
| Поведение вне диапазона | null | `getPoint()` возвращает null. `getRange()` сохраняет ключ, значение = null. Никаких выдуманных значений, но UI получает полную таблицу запрошенных позиций. |
| Трансформации | `map(callable)` + `multiply(float)` | Immutable. Поддерживает применение коэффициентов и кастомных callable. Используется при сборке спецификации CoatingSystem. |
| isCalculated после map/multiply | Сохраняется как был | Трансформация не меняет «происхождение» точки. Если нужно явно пометить весь профиль как расчётный — это отдельная операция. |
| Пометка расчётной точки | `bool isCalculated = false` в `TimeAtTemperature` | Простой флаг. Сохраняемые точки всегда false. |
| Хранение в БД | JSONB (по одной колонке на профиль) | Атомарно, не требует ассоциаций. SQL-фасеты по конкретной температуре — пока не нужны. |
| Кросс-инвариант `dryToTouch <= fullCure` | НЕ вводим | Усложняет API. Исходно не было. |
| Удаление `setTags` | Без замены на публичный setter | Только `addTag/removeTag/replaceTags`. |
| `new \Exception` | Замена на `AppException` | Единый стиль доменных исключений. |
| Тип `recoatingInterval` | `PositiveNumberRange` или две float | По решению пользователя: остаётся как два отдельных float с проверкой в `assertInvariants()`. Температурная зависимость для интервала перекрытия отложена. |

## План работ (укрупнённо)

Делится на коммиты:

1. **Series + SeriesPoint** — каркас в Shared. Тесты.
2. **TimeAtTemperature + DryingTimeSeries** — конкретные классы в Coatings. Тесты.
3. **DBAL Type `drying_time_series`** — регистрация в Doctrine.
4. **Coating refactor** — поля, бизнес-методы, `assertInvariants()`, удаление сеттеров. Маппинг ORM XML (embedded для DftRange, custom type для профилей).
5. **Миграция БД** — `dryToTouch/fullCure FLOAT → JSONB`, миграция данных в одну точку при +20°C. Перенос DFT на embedded.
6. **CoatingFactory** — обновление сигнатуры `create()`.
7. **CoatingMapper** — сборка `DftRange` и `DryingTimeSeries` из DTO.
8. **CoatingDTO + DTOTransformer** — структура для профилей.
9. **UpdateCoatingCommandHandler** — переход на `change*`-методы.
10. **AddAction / UpdateAction** — формы и парсинг входных данных.
11. **UI/формы** — таблица «+/− строка» для профилей (отдельная задача фронта).

После этого PR проект готов к началу работ над FTS.

## Открытые вопросы

- **ORM embedded для `DftRange`** — нюансы маппинга `nested embeddable` (DftRange содержит PositiveNumberRange) с Doctrine. Разрешим при реализации шага 4. Резервный план: lifecycle callbacks (`@PostLoad`/`@PreUpdate`) или custom DBAL Type для всего DftRange.
- **UI-форма для профилей** — план фронт-работы оформляется отдельно после согласования бэка.

## Что НЕ входит

- FTS, trgm-поиск, embeddings, pgvector.
- Новые поля Coating (область применения и т.п.).
- Температурно-зависимый `recoatingInterval`.
- Универсальные единицы времени (минуты/часы) в `TimeAtTemperature`.
- Универсальные единицы температуры (только Цельсий).
- Кросс-инвариант между `dryToTouch` и `fullCure` профилями.

## Ссылки

- `app/src/Coatings/Domain/Aggregate/Coating/Coating.php` — текущий агрегат
- `app/src/Coatings/Domain/Aggregate/Coating/DftRange.php` — уже введённый VO
- `app/src/Shared/Domain/Aggregate/ValueObject/NumberRange.php` — паттерн, на который ориентируется `Series`
- `app/src/Coatings/Application/UseCase/Command/UpdateCoating/UpdateCoatingCommandHandler.php` — точка миграции на `change*`-методы
