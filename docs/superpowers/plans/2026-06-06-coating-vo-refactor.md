# Coating Aggregate VO Refactor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Перевести `Coating` агрегат на типизированные VO (`DftRange`, `DryingTimeSeries`), убрать публичные сеттеры, добавить кросс-полевые инварианты. Подготовить модель к работе над полнотекстовым поиском.

**Architecture:** Абстрактный каркас `Series` + `SeriesPoint` в Shared (точки «ключ→значение» с интерполяцией, трансформацией и автоматической валидацией). Конкретный `DryingTimeSeries` поверх него — для `dryToTouch`/`fullCure`. `dryToTouch`/`fullCure` в БД переезжают из FLOAT в JSONB через кастомный DBAL Type. Coating заменяет публичные `setXxx` на бизнес-методы `changeXxx`, каждый вызывает `assertInvariants()`.

**Tech Stack:** PHP 8.3, Symfony, Doctrine ORM/DBAL, PostgreSQL 17 (JSONB), PHPUnit 9.5, docker-compose.

**Spec:** `docs/superpowers/specs/2026-06-06-coating-vo-refactor-design.md`

---

## File Structure

### Создаются

| Файл | Ответственность |
|---|---|
| `app/src/Shared/Domain/Aggregate/ValueObject/SeriesPoint.php` | Интерфейс точки серии (key, value, isCalculated) |
| `app/src/Shared/Domain/Aggregate/ValueObject/Series.php` | Абстрактный каркас серии — валидация, поиск, интерполяция, map, multiply |
| `app/src/Coatings/Domain/Aggregate/Coating/TimeAtTemperature.php` | Точка серии — температура (int Celsius) + время (float минут) |
| `app/src/Coatings/Domain/Aggregate/Coating/DryingTimeSeries.php` | Серия времён сушки — extends Series, проверяет монотонность |
| `app/src/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesType.php` | DBAL Type для прозрачной (де)сериализации `DryingTimeSeries <-> JSONB` |
| `app/migrations/VersionYYYYMMDDHHMMSS.php` | Миграция БД: `dry_to_touch FLOAT/full_cure FLOAT → JSONB` |
| `app/tests/Unit/Shared/Domain/Aggregate/ValueObject/SeriesTest.php` | Unit-тесты Series через тестовый subclass |
| `app/tests/Unit/Coatings/Domain/Aggregate/Coating/TimeAtTemperatureTest.php` | Unit-тесты TimeAtTemperature |
| `app/tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php` | Unit-тесты DryingTimeSeries |
| `app/tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php` | Unit-тесты Coating (бизнес-методы, инварианты) |

### Модифицируются

| Файл | Что меняется |
|---|---|
| `app/src/Coatings/Domain/Aggregate/Coating/Coating.php` | Убираются публичные `setXxx`, добавляются `changeXxx` + `assertInvariants()`, типы dryToTouch/fullCure становятся DryingTimeSeries, `new \Exception` → `AppException`, удаляется `setTags(Collection)` |
| `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml` | `dry_to_touch` / `full_cure` маппятся через custom type `drying_time_series` |
| `app/src/Coatings/Domain/Factory/CoatingFactory.php` | Параметр `float $dryToTouch/$fullCure` → `DryingTimeSeries`; убираются `tdsDft/minDft/maxDft`, добавляется `DftRange` |
| `app/src/Coatings/Application/DTO/Coatings/CoatingDTO.php` | `?float $dryToTouch/$fullCure` → `?array`. `?int $tdsDft/minDft/maxDft` → `?array $dftRange` |
| `app/src/Coatings/Application/DTO/Coatings/CoatingDTOTransformer.php` | Сериализует профили и DftRange в DTO-структуры |
| `app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php` | Конструирует DftRange и DryingTimeSeries из массивов DTO |
| `app/src/Coatings/Application/UseCase/Command/UpdateCoating/UpdateCoatingCommandHandler.php` | Заменяет `setXxx` → `changeXxx`, сборка профилей из DTO |
| `app/config/packages/doctrine.yaml` | Регистрация custom DBAL type `drying_time_series` |

### Не трогаем в этом плане

- UI/формы для редактирования профилей (frontend) — отдельная задача.
- FTS и trgm-поиск.
- `recoatingInterval` — остаётся как `float min/max` с проверкой в `assertInvariants()`.

---

## Conventions

**Команды запуска тестов (через docker-compose):**

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/Unit/path/to/TestName.php
```

**Команды миграций:**

```bash
docker-compose exec manager_php-cli php bin/console doctrine:migrations:generate
docker-compose exec manager_php-cli php bin/console doctrine:migrations:migrate --no-interaction
```

**Сообщения коммитов:** короткие, в стиле репо (`feat. ...`, `fix. ...`, `refactor. ...`).

---

## Phase 1: Series scaffold (Shared + Coatings VO)

Эта фаза создаёт переиспользуемый каркас и конкретные VO для drying. Тестируется автономно. По окончании фазы можно коммитить промежуточный PR.

### Task 1: SeriesPoint interface

**Files:**
- Create: `app/src/Shared/Domain/Aggregate/ValueObject/SeriesPoint.php`

- [ ] **Step 1: Создать интерфейс**

```php
<?php
declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

use JsonSerializable;

interface SeriesPoint extends JsonSerializable
{
    public function getKey(): int|float;
    public function getValue(): int|float;
    public function isCalculated(): bool;
}
```

- [ ] **Step 2: Проверить, что autoload видит интерфейс**

```bash
docker-compose exec manager_php-cli composer dump-autoload
docker-compose exec manager_php-cli php -r "var_dump(interface_exists('App\\Shared\\Domain\\Aggregate\\ValueObject\\SeriesPoint'));"
```

Ожидаемый вывод: `bool(true)`

- [ ] **Step 3: Коммит**

```bash
git add app/src/Shared/Domain/Aggregate/ValueObject/SeriesPoint.php
git commit -m "feat. add SeriesPoint interface"
```

---

### Task 2: Series abstract class

**Files:**
- Create: `app/src/Shared/Domain/Aggregate/ValueObject/Series.php`
- Create: `app/tests/Unit/Shared/Domain/Aggregate/ValueObject/SeriesTest.php`
- Create: `app/tests/Unit/Shared/Domain/Aggregate/ValueObject/Fixtures/IntSeriesPoint.php`
- Create: `app/tests/Unit/Shared/Domain/Aggregate/ValueObject/Fixtures/TestIntSeries.php`

- [ ] **Step 1: Создать тестовый fixture subclass для тестирования Series**

`app/tests/Unit/Shared/Domain/Aggregate/ValueObject/Fixtures/IntSeriesPoint.php`:

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\ValueObject\Fixtures;

use App\Shared\Domain\Aggregate\ValueObject\SeriesPoint;

final readonly class IntSeriesPoint implements SeriesPoint
{
    public function __construct(
        public int $key,
        public int $value,
        public bool $isCalculated = false,
    ) {}

    public function getKey(): int { return $this->key; }
    public function getValue(): int { return $this->value; }
    public function isCalculated(): bool { return $this->isCalculated; }
    public function jsonSerialize(): array { return ['key' => $this->key, 'value' => $this->value]; }
}
```

`app/tests/Unit/Shared/Domain/Aggregate/ValueObject/Fixtures/TestIntSeries.php`:

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\ValueObject\Fixtures;

use App\Shared\Domain\Aggregate\ValueObject\Series;
use App\Shared\Domain\Aggregate\ValueObject\SeriesPoint;

final readonly class TestIntSeries extends Series
{
    protected function validate(): void
    {
        // без специальных правил для теста
    }

    protected function createPoint(int|float $key, int|float $value, bool $isCalculated): SeriesPoint
    {
        return new IntSeriesPoint((int) $key, (int) $value, $isCalculated);
    }
}
```

- [ ] **Step 2: Написать падающий тест на основные операции Series**

`app/tests/Unit/Shared/Domain/Aggregate/ValueObject/SeriesTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Unit\Shared\Domain\Aggregate\ValueObject\Fixtures\IntSeriesPoint;
use App\Tests\Unit\Shared\Domain\Aggregate\ValueObject\Fixtures\TestIntSeries;
use PHPUnit\Framework\TestCase;

class SeriesTest extends TestCase
{
    public function testEmptySeriesThrows(): void
    {
        $this->expectException(AppException::class);
        new TestIntSeries([]);
    }

    public function testNonPointElementThrows(): void
    {
        $this->expectException(AppException::class);
        new TestIntSeries(['not a point']);
    }

    public function testDuplicateKeysThrow(): void
    {
        $this->expectException(AppException::class);
        new TestIntSeries([
            new IntSeriesPoint(10, 100),
            new IntSeriesPoint(10, 200),
        ]);
    }

    public function testAutoSortByKey(): void
    {
        $series = new TestIntSeries([
            new IntSeriesPoint(30, 25),
            new IntSeriesPoint(10, 100),
            new IntSeriesPoint(20, 50),
        ]);
        $keys = array_map(fn($p) => $p->getKey(), $series->points);
        $this->assertSame([10, 20, 30], $keys);
    }

    public function testGetPointExact(): void
    {
        $series = new TestIntSeries([
            new IntSeriesPoint(10, 100),
            new IntSeriesPoint(20, 50),
        ]);
        $point = $series->getPoint(10);
        $this->assertNotNull($point);
        $this->assertSame(10, $point->getKey());
        $this->assertSame(100, $point->getValue());
        $this->assertFalse($point->isCalculated());
    }

    public function testGetPointInterpolated(): void
    {
        $series = new TestIntSeries([
            new IntSeriesPoint(20, 50),
            new IntSeriesPoint(30, 25),
        ]);
        $point = $series->getPoint(25);
        $this->assertNotNull($point);
        $this->assertSame(25, $point->getKey());
        $this->assertEqualsWithDelta(37, $point->getValue(), 1.0);
        $this->assertTrue($point->isCalculated());
    }

    public function testGetPointOutOfRangeReturnsNull(): void
    {
        $series = new TestIntSeries([
            new IntSeriesPoint(10, 100),
            new IntSeriesPoint(20, 50),
        ]);
        $this->assertNull($series->getPoint(5));
        $this->assertNull($series->getPoint(25));
    }

    public function testGetRangeKeepsKeysIncludingOutOfRange(): void
    {
        $series = new TestIntSeries([
            new IntSeriesPoint(10, 100),
            new IntSeriesPoint(20, 50),
            new IntSeriesPoint(30, 25),
        ]);
        $range = $series->getRange(10, 50, 10);
        $this->assertCount(5, $range);
        $this->assertNotNull($range[10]);
        $this->assertNotNull($range[20]);
        $this->assertNotNull($range[30]);
        $this->assertNull($range[40]);
        $this->assertNull($range[50]);
    }

    public function testMapReturnsNewSeries(): void
    {
        $original = new TestIntSeries([
            new IntSeriesPoint(10, 100),
            new IntSeriesPoint(20, 50),
        ]);
        $doubled = $original->map(fn($v) => $v * 2);
        $this->assertNotSame($original, $doubled);
        $this->assertSame(200, $doubled->points[0]->getValue());
        $this->assertSame(100, $doubled->points[1]->getValue());
        $this->assertSame(100, $original->points[0]->getValue());
    }

    public function testMultiplyShortcut(): void
    {
        $series = new TestIntSeries([
            new IntSeriesPoint(10, 100),
        ]);
        $tripled = $series->multiply(3);
        $this->assertSame(300, $tripled->points[0]->getValue());
    }
}
```

- [ ] **Step 3: Запустить — должен FAIL на классе Series**

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/Unit/Shared/Domain/Aggregate/ValueObject/SeriesTest.php
```

Ожидаемый вывод: ошибка `Class "App\Shared\Domain\Aggregate\ValueObject\Series" not found` или подобная.

- [ ] **Step 4: Реализовать Series**

`app/src/Shared/Domain/Aggregate/ValueObject/Series.php`:

```php
<?php
declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;
use JsonSerializable;

abstract readonly class Series implements JsonSerializable
{
    /** @var SeriesPoint[] отсортирован по getKey() возрастающе, ключи уникальны */
    public array $points;

    public function __construct(array $points)
    {
        if (count($points) === 0) {
            throw new AppException('Series не может быть пустой.');
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

    abstract protected function createPoint(int|float $key, int|float $value, bool $isCalculated): SeriesPoint;

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

    /** @return array<int|float, ?SeriesPoint> */
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

    public function map(callable $fn): static
    {
        $newPoints = [];
        foreach ($this->points as $p) {
            $newValue = $fn($p->getValue(), $p->getKey());
            $newPoints[] = $this->createPoint($p->getKey(), $newValue, $p->isCalculated());
        }
        return new static($newPoints);
    }

    public function multiply(float $factor): static
    {
        return $this->map(fn(int|float $v) => $v * $factor);
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

- [ ] **Step 5: Запустить тесты — должны пройти**

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/Unit/Shared/Domain/Aggregate/ValueObject/SeriesTest.php
```

Ожидаемый вывод: `OK (10 tests, ... assertions)`.

- [ ] **Step 6: Коммит**

```bash
git add app/src/Shared/Domain/Aggregate/ValueObject/Series.php app/tests/Unit/Shared/Domain/Aggregate/ValueObject/
git commit -m "feat. add Series abstract VO with interpolation and transforms"
```

---

### Task 3: TimeAtTemperature

**Files:**
- Create: `app/src/Coatings/Domain/Aggregate/Coating/TimeAtTemperature.php`
- Create: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/TimeAtTemperatureTest.php`

- [ ] **Step 1: Написать падающий тест**

`app/tests/Unit/Coatings/Domain/Aggregate/Coating/TimeAtTemperatureTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

class TimeAtTemperatureTest extends TestCase
{
    public function testValidConstruction(): void
    {
        $point = new TimeAtTemperature(20, 10.5);
        $this->assertSame(20, $point->celsius);
        $this->assertSame(10.5, $point->minutes);
        $this->assertFalse($point->isCalculated);
    }

    public function testNegativeMinutesThrow(): void
    {
        $this->expectException(AppException::class);
        new TimeAtTemperature(20, -1.0);
    }

    public function testNegativeCelsiusAllowed(): void
    {
        $point = new TimeAtTemperature(-10, 60.0);
        $this->assertSame(-10, $point->celsius);
    }

    public function testIsCalculatedFlag(): void
    {
        $point = new TimeAtTemperature(20, 10.0, isCalculated: true);
        $this->assertTrue($point->isCalculated);
    }

    public function testGetKeyReturnsCelsius(): void
    {
        $point = new TimeAtTemperature(20, 10.0);
        $this->assertSame(20, $point->getKey());
    }

    public function testGetValueReturnsMinutes(): void
    {
        $point = new TimeAtTemperature(20, 10.0);
        $this->assertSame(10.0, $point->getValue());
    }

    public function testJsonSerialize(): void
    {
        $point = new TimeAtTemperature(20, 10.5);
        $this->assertSame(['celsius' => 20, 'minutes' => 10.5], $point->jsonSerialize());
    }
}
```

- [ ] **Step 2: Запустить — должен FAIL**

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/TimeAtTemperatureTest.php
```

Ожидаемый вывод: `Class "App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature" not found`.

- [ ] **Step 3: Реализовать TimeAtTemperature**

`app/src/Coatings/Domain/Aggregate/Coating/TimeAtTemperature.php`:

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Domain\Aggregate\ValueObject\SeriesPoint;
use App\Shared\Infrastructure\Exception\AppException;

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

- [ ] **Step 4: Запустить — должен пройти**

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/TimeAtTemperatureTest.php
```

Ожидаемый: `OK (7 tests, ...)`.

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Domain/Aggregate/Coating/TimeAtTemperature.php app/tests/Unit/Coatings/Domain/Aggregate/Coating/TimeAtTemperatureTest.php
git commit -m "feat. add TimeAtTemperature value object"
```

---

### Task 4: DryingTimeSeries

**Files:**
- Create: `app/src/Coatings/Domain/Aggregate/Coating/DryingTimeSeries.php`
- Create: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php`

- [ ] **Step 1: Написать падающий тест**

`app/tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

class DryingTimeSeriesTest extends TestCase
{
    public function testRejectsNonTimeAtTemperaturePoint(): void
    {
        $this->expectException(AppException::class);
        new DryingTimeSeries(['not a TimeAtTemperature']);
    }

    public function testValidMonotonicProfile(): void
    {
        $series = new DryingTimeSeries([
            new TimeAtTemperature(5, 30.0),
            new TimeAtTemperature(20, 10.0),
            new TimeAtTemperature(30, 5.0),
        ]);
        $this->assertCount(3, $series->points);
        $this->assertSame(5, $series->points[0]->getKey());
        $this->assertSame(30, $series->points[2]->getKey());
    }

    public function testNonMonotonicThrows(): void
    {
        $this->expectException(AppException::class);
        // При +30°C время больше, чем при +20°C — нарушение физики
        new DryingTimeSeries([
            new TimeAtTemperature(20, 10.0),
            new TimeAtTemperature(30, 20.0),
        ]);
    }

    public function testEqualValuesAllowed(): void
    {
        // Нестрогая монотонность — равные значения OK
        $series = new DryingTimeSeries([
            new TimeAtTemperature(20, 10.0),
            new TimeAtTemperature(25, 10.0),
        ]);
        $this->assertCount(2, $series->points);
    }

    public function testSinglePointAllowed(): void
    {
        $series = new DryingTimeSeries([new TimeAtTemperature(20, 10.0)]);
        $this->assertCount(1, $series->points);
    }

    public function testInterpolatesBetweenPoints(): void
    {
        $series = new DryingTimeSeries([
            new TimeAtTemperature(20, 10.0),
            new TimeAtTemperature(30, 5.0),
        ]);
        $point = $series->getPoint(25);
        $this->assertNotNull($point);
        $this->assertEqualsWithDelta(7.5, $point->getValue(), 0.01);
        $this->assertTrue($point->isCalculated());
    }

    public function testMultiplyReturnsNewSeries(): void
    {
        $original = new DryingTimeSeries([
            new TimeAtTemperature(20, 10.0),
        ]);
        $boosted = $original->multiply(1.2);
        $this->assertNotSame($original, $boosted);
        $this->assertEqualsWithDelta(12.0, $boosted->points[0]->getValue(), 0.01);
        $this->assertEqualsWithDelta(10.0, $original->points[0]->getValue(), 0.01);
    }

    public function testJsonSerialize(): void
    {
        $series = new DryingTimeSeries([
            new TimeAtTemperature(20, 10.0),
            new TimeAtTemperature(30, 5.0),
        ]);
        $this->assertSame([
            ['celsius' => 20, 'minutes' => 10.0],
            ['celsius' => 30, 'minutes' => 5.0],
        ], $series->jsonSerialize());
    }
}
```

- [ ] **Step 2: Запустить — должен FAIL**

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php
```

- [ ] **Step 3: Реализовать DryingTimeSeries**

`app/src/Coatings/Domain/Aggregate/Coating/DryingTimeSeries.php`:

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Domain\Aggregate\ValueObject\Series;
use App\Shared\Domain\Aggregate\ValueObject\SeriesPoint;
use App\Shared\Infrastructure\Exception\AppException;

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

- [ ] **Step 4: Запустить тесты — должны пройти**

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php
```

Ожидаемый: `OK (8 tests, ...)`.

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Domain/Aggregate/Coating/DryingTimeSeries.php app/tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php
git commit -m "feat. add DryingTimeSeries with monotonicity invariant"
```

---

## Phase 2: Persistence (DBAL type + migration)

### Task 5: DryingTimeSeriesType DBAL + регистрация

**Files:**
- Create: `app/src/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesType.php`
- Modify: `app/config/packages/doctrine.yaml` — добавить `types: drying_time_series: ...`
- Create: `app/tests/Unit/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesTypeTest.php`

- [ ] **Step 1: Написать падающий тест на (де)сериализацию**

`app/tests/Unit/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesTypeTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Infrastructure\Database\DBAL\DryingTimeSeriesType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\TestCase;

class DryingTimeSeriesTypeTest extends TestCase
{
    private DryingTimeSeriesType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        if (!Type::hasType(DryingTimeSeriesType::NAME)) {
            Type::addType(DryingTimeSeriesType::NAME, DryingTimeSeriesType::class);
        }
        $this->type = Type::getType(DryingTimeSeriesType::NAME);
        $this->platform = new PostgreSQLPlatform();
    }

    public function testToDatabase(): void
    {
        $series = new DryingTimeSeries([
            new TimeAtTemperature(20, 10.0),
            new TimeAtTemperature(30, 5.0),
        ]);
        $json = $this->type->convertToDatabaseValue($series, $this->platform);
        $this->assertSame(
            '[{"celsius":20,"minutes":10},{"celsius":30,"minutes":5}]',
            $json
        );
    }

    public function testFromDatabase(): void
    {
        $json = '[{"celsius":20,"minutes":10},{"celsius":30,"minutes":5}]';
        $series = $this->type->convertToPHPValue($json, $this->platform);
        $this->assertInstanceOf(DryingTimeSeries::class, $series);
        $this->assertCount(2, $series->points);
        $this->assertSame(20, $series->points[0]->getKey());
        $this->assertSame(10.0, $series->points[0]->getValue());
    }

    public function testNullRoundtrip(): void
    {
        $this->assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        $this->assertNull($this->type->convertToPHPValue(null, $this->platform));
    }
}
```

- [ ] **Step 2: Запустить — должен FAIL**

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesTypeTest.php
```

- [ ] **Step 3: Реализовать DBAL Type**

`app/src/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesType.php`:

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

final class DryingTimeSeriesType extends JsonType
{
    public const NAME = 'drying_time_series';

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof DryingTimeSeries) {
            throw new \InvalidArgumentException(
                'Expected DryingTimeSeries, got ' . (is_object($value) ? $value::class : gettype($value))
            );
        }
        return parent::convertToDatabaseValue($value->jsonSerialize(), $platform);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?DryingTimeSeries
    {
        if ($value === null) {
            return null;
        }
        $raw = parent::convertToPHPValue($value, $platform);
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Expected JSON array for DryingTimeSeries.');
        }
        $points = array_map(
            fn(array $p) => new TimeAtTemperature((int) $p['celsius'], (float) $p['minutes']),
            $raw
        );
        return new DryingTimeSeries($points);
    }
}
```

- [ ] **Step 4: Зарегистрировать тип в Doctrine**

Modify `app/config/packages/doctrine.yaml` — добавить под `doctrine.dbal.types:`:

```yaml
doctrine:
    dbal:
        types:
            drying_time_series: App\Coatings\Infrastructure\Database\DBAL\DryingTimeSeriesType
```

(Если ключ `types:` уже есть — добавить строку в него. Если нет — создать.)

- [ ] **Step 5: Запустить тесты + sanity check регистрации**

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesTypeTest.php
docker-compose exec manager_php-cli php bin/console debug:container --parameters | grep drying || true
```

Ожидаемый: тесты `OK (3 tests, ...)`.

- [ ] **Step 6: Коммит**

```bash
git add app/src/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesType.php \
        app/config/packages/doctrine.yaml \
        app/tests/Unit/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesTypeTest.php
git commit -m "feat. add drying_time_series DBAL type"
```

---

### Task 6: БД миграция (FLOAT → JSONB + данные)

**Files:**
- Create: `app/migrations/VersionYYYYMMDDHHMMSS.php` (имя сгенерируется автоматически)

- [ ] **Step 1: Сгенерировать миграцию**

```bash
docker-compose exec manager_php-cli php bin/console doctrine:migrations:generate
```

Запомнить путь к созданному файлу — он будет вида `app/migrations/Version20260606HHMMSS.php`.

- [ ] **Step 2: Заполнить миграцию**

Открыть созданный файл и заполнить `up()` и `down()`:

```php
public function getDescription(): string
{
    return 'Convert dry_to_touch and full_cure from FLOAT to JSONB with default +20C point';
}

public function up(Schema $schema): void
{
    $this->addSql("
        ALTER TABLE coatings_coating
        ALTER COLUMN dry_to_touch TYPE JSONB
        USING jsonb_build_array(jsonb_build_object('celsius', 20, 'minutes', dry_to_touch::float))
    ");

    $this->addSql("
        ALTER TABLE coatings_coating
        ALTER COLUMN full_cure TYPE JSONB
        USING jsonb_build_array(jsonb_build_object('celsius', 20, 'minutes', full_cure::float))
    ");
}

public function down(Schema $schema): void
{
    $this->addSql("
        ALTER TABLE coatings_coating
        ALTER COLUMN dry_to_touch TYPE DOUBLE PRECISION
        USING (dry_to_touch->0->>'minutes')::double precision
    ");

    $this->addSql("
        ALTER TABLE coatings_coating
        ALTER COLUMN full_cure TYPE DOUBLE PRECISION
        USING (full_cure->0->>'minutes')::double precision
    ");
}
```

- [ ] **Step 3: Применить миграцию**

```bash
docker-compose exec manager_php-cli php bin/console doctrine:migrations:migrate --no-interaction
```

Ожидаемый вывод: миграция выполнена, нет ошибок.

- [ ] **Step 4: Проверить структуру**

```bash
docker-compose exec manager_db psql -U "$DB_USER" -d "$DB_NAME" -c "\d coatings_coating" | grep -E "dry_to_touch|full_cure"
```

Ожидаемый: обе колонки тип `jsonb`.

```bash
docker-compose exec manager_db psql -U "$DB_USER" -d "$DB_NAME" -c "SELECT id, dry_to_touch FROM coatings_coating LIMIT 1"
```

Ожидаемый: формат `[{"celsius": 20, "minutes": <число>}]`.

- [ ] **Step 5: Коммит**

```bash
git add app/migrations/Version*.php
git commit -m "feat. migrate dry_to_touch and full_cure to jsonb"
```

---

## Phase 3: Coating refactor + callsites

После этой фазы — приложение работает с новыми типами. Сеттеры заменены, инварианты проверяются, FTS можно подключать.

### Task 7: Coating.php — финальное состояние

**Files:**
- Modify: `app/src/Coatings/Domain/Aggregate/Coating/Coating.php` (полная переработка через Write)
- Modify: `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml`
- Create: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php`

- [ ] **Step 1: Написать падающие тесты для Coating инвариантов и бизнес-методов**

`app/tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\Specification\UniqueTitleCoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\UniqueTitleManufacturerSpecification;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

class CoatingTest extends TestCase
{
    private function makeSpec(): CoatingSpecification
    {
        $coatingRepo = $this->createMock(CoatingRepositoryInterface::class);
        $coatingRepo->method('findOneByTitle')->willReturn(null);
        return new CoatingSpecification(new UniqueTitleCoatingSpecification($coatingRepo));
    }

    private function makeManufacturer(): Manufacturer
    {
        $repo = $this->createMock(ManufacturerRepositoryInterface::class);
        $repo->method('findOneByTitle')->willReturn(null);
        $spec = new ManufacturerSpecification(new UniqueTitleManufacturerSpecification($repo));
        return new Manufacturer('Acme Coatings', $spec);
    }

    private function makeCoating(
        ?DryingTimeSeries $dryToTouch = null,
        ?DryingTimeSeries $fullCure = null,
        float $minRecoatingInterval = 30.0,
        float $maxRecoatingInterval = 120.0,
    ): Coating {
        $dft = new DftRange(new PositiveNumberRange(50, 150), 100);
        return new Coating(
            title: 'Test Coating',
            description: 'desc',
            volumeSolid: 60,
            massDensity: 1.3,
            dftRange: $dft,
            applicationMinTemp: 5,
            dryToTouch: $dryToTouch ?? new DryingTimeSeries([new TimeAtTemperature(20, 10.0)]),
            minRecoatingInterval: $minRecoatingInterval,
            maxRecoatingInterval: $maxRecoatingInterval,
            fullCure: $fullCure ?? new DryingTimeSeries([new TimeAtTemperature(20, 60.0)]),
            pack: 20.0,
            thinner: null,
            manufacturer: $this->makeManufacturer(),
            specification: $this->makeSpec(),
        );
    }

    public function testConstructsValidCoating(): void
    {
        $coating = $this->makeCoating();
        $this->assertSame('Test Coating', $coating->getTitle());
        $this->assertSame(60, $coating->getVolumeSolid());
    }

    public function testInvariantMinGreaterThanMaxRecoatingInterval(): void
    {
        $this->expectException(AppException::class);
        $this->makeCoating(
            minRecoatingInterval: 200.0,
            maxRecoatingInterval: 100.0,
        );
    }

    public function testChangeTitleUpdatesAndValidates(): void
    {
        $coating = $this->makeCoating();
        $coating->changeTitle('New Title');
        $this->assertSame('New Title', $coating->getTitle());
    }

    public function testChangeVolumeSolidOutOfRangeThrows(): void
    {
        $coating = $this->makeCoating();
        $this->expectException(AppException::class);
        $coating->changeVolumeSolid(0);
    }

    public function testChangePackOutOfRangeThrows(): void
    {
        $coating = $this->makeCoating();
        $this->expectException(AppException::class);
        $coating->changePack(2000.0);
    }

    public function testChangeDryToTouchUpdatesProfile(): void
    {
        $coating = $this->makeCoating();
        $newProfile = new DryingTimeSeries([
            new TimeAtTemperature(20, 15.0),
        ]);
        $coating->changeDryToTouch($newProfile);
        $this->assertSame($newProfile, $coating->getDryToTouch());
    }

    public function testChangeMinRecoatingIntervalViolatingInvariantThrows(): void
    {
        $coating = $this->makeCoating();
        $this->expectException(AppException::class);
        $coating->changeMinRecoatingInterval(500.0);
    }
}
```

- [ ] **Step 2: Запустить — должны FAIL (нет changeXxx, нет нужного конструктора)**

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php
```

- [ ] **Step 3: Переписать Coating.php**

`app/src/Coatings/Domain/Aggregate/Coating/Coating.php`:

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class Coating extends Aggregate
{
    public const PROTECTION_TYPE = 'CoatingProtectionType';
    public const COAT_TYPE = 'CoatingCoatType';

    private readonly string $id;
    private string $title;
    private string $description;
    private int $volumeSolid;
    private float $massDensity;
    private DftRange $dftRange;
    private int $applicationMinTemp;
    private DryingTimeSeries $dryToTouch;
    private float $minRecoatingInterval;
    private float $maxRecoatingInterval;
    private DryingTimeSeries $fullCure;
    private Manufacturer $manufacturer;
    private CoatingSpecification $specification;
    private float $pack;
    private ?string $thinner;

    /** @var Collection<CoatingTag> */
    private Collection $tags;

    public function __construct(
        string               $title,
        string               $description,
        int                  $volumeSolid,
        float                $massDensity,
        DftRange             $dftRange,
        int                  $applicationMinTemp,
        DryingTimeSeries     $dryToTouch,
        float                $minRecoatingInterval,
        float                $maxRecoatingInterval,
        DryingTimeSeries     $fullCure,
        float                $pack,
        ?string              $thinner,
        Manufacturer         $manufacturer,
        CoatingSpecification $specification,
    ) {
        $this->id = UuidService::generate();
        $this->tags = new ArrayCollection();
        $this->specification = $specification;
        $this->manufacturer = $manufacturer;
        $this->dftRange = $dftRange;
        $this->applicationMinTemp = $applicationMinTemp;
        $this->dryToTouch = $dryToTouch;
        $this->fullCure = $fullCure;
        $this->setTitle($title);
        $this->setDescription($description);
        $this->setVolumeSolid($volumeSolid);
        $this->setMassDensity($massDensity);
        $this->setMinRecoatingInterval($minRecoatingInterval);
        $this->setMaxRecoatingInterval($maxRecoatingInterval);
        $this->setPack($pack);
        $this->setThinner($thinner);

        $this->assertInvariants();
    }

    public function getId(): string { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): string { return $this->description; }
    public function getVolumeSolid(): int { return $this->volumeSolid; }
    public function getMassDensity(): float { return $this->massDensity; }
    public function getDftRange(): DftRange { return $this->dftRange; }
    public function getApplicationMinTemp(): int { return $this->applicationMinTemp; }
    public function getDryToTouch(): DryingTimeSeries { return $this->dryToTouch; }
    public function getMinRecoatingInterval(): float { return $this->minRecoatingInterval; }
    public function getMaxRecoatingInterval(): float { return $this->maxRecoatingInterval; }
    public function getFullCure(): DryingTimeSeries { return $this->fullCure; }
    public function getManufacturer(): Manufacturer { return $this->manufacturer; }
    public function getPack(): float { return $this->pack; }
    public function getThinner(): ?string { return $this->thinner; }
    public function getTags(): Collection { return $this->tags; }

    public function changeTitle(string $title): void
    {
        $this->setTitle($title);
        $this->assertInvariants();
    }

    public function changeDescription(string $description): void
    {
        $this->setDescription($description);
        $this->assertInvariants();
    }

    public function changeVolumeSolid(int $volumeSolid): void
    {
        $this->setVolumeSolid($volumeSolid);
        $this->assertInvariants();
    }

    public function changeMassDensity(float $massDensity): void
    {
        $this->setMassDensity($massDensity);
        $this->assertInvariants();
    }

    public function changeDftRange(DftRange $dftRange): void
    {
        $this->dftRange = $dftRange;
        $this->assertInvariants();
    }

    public function changeApplicationMinTemp(int $applicationMinTemp): void
    {
        $this->applicationMinTemp = $applicationMinTemp;
        $this->assertInvariants();
    }

    public function changeDryToTouch(DryingTimeSeries $dryToTouch): void
    {
        $this->dryToTouch = $dryToTouch;
        $this->assertInvariants();
    }

    public function changeMinRecoatingInterval(float $minRecoatingInterval): void
    {
        $this->setMinRecoatingInterval($minRecoatingInterval);
        $this->assertInvariants();
    }

    public function changeMaxRecoatingInterval(float $maxRecoatingInterval): void
    {
        $this->setMaxRecoatingInterval($maxRecoatingInterval);
        $this->assertInvariants();
    }

    public function changeFullCure(DryingTimeSeries $fullCure): void
    {
        $this->fullCure = $fullCure;
        $this->assertInvariants();
    }

    public function changePack(float $pack): void
    {
        $this->setPack($pack);
        $this->assertInvariants();
    }

    public function changeThinner(?string $thinner): void
    {
        $this->setThinner($thinner);
        $this->assertInvariants();
    }

    public function changeManufacturer(Manufacturer $manufacturer): void
    {
        $this->manufacturer = $manufacturer;
        $this->assertInvariants();
    }

    public function addTag(CoatingTag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
    }

    public function removeTag(CoatingTag $tag): void
    {
        $this->tags->removeElement($tag);
    }

    /** @param CoatingTag[] $tags */
    public function replaceTags(array $tags): void
    {
        $this->tags->clear();
        foreach ($tags as $tag) {
            $this->addTag($tag);
        }
    }

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

    private function setTitle(string $title): void
    {
        AssertService::maxLength($title, 100);
        $this->title = $title;
        $this->specification->uniqueTitleCoatingSpecification->satisfy($this);
    }

    private function setDescription(string $description): void
    {
        AssertService::maxLength($description, 750);
        $this->description = $description;
    }

    private function setVolumeSolid(int $volumeSolid): void
    {
        if ($volumeSolid < 1 || $volumeSolid > 100) {
            throw new AppException('Сухой остаток (volumeSolid) должен быть в диапазоне 1..100.');
        }
        $this->volumeSolid = $volumeSolid;
    }

    private function setMassDensity(float $massDensity): void
    {
        AssertService::greaterThanEq($massDensity, 0);
        $this->massDensity = $massDensity;
    }

    private function setMinRecoatingInterval(float $minRecoatingInterval): void
    {
        AssertService::greaterThanEq($minRecoatingInterval, 0);
        $this->minRecoatingInterval = $minRecoatingInterval;
    }

    private function setMaxRecoatingInterval(float $maxRecoatingInterval): void
    {
        AssertService::greaterThanEq($maxRecoatingInterval, 0);
        $this->maxRecoatingInterval = $maxRecoatingInterval;
    }

    private function setPack(float $pack): void
    {
        if ($pack < 1 || $pack > 1000) {
            throw new AppException('Упаковка (pack) должна быть в диапазоне 1..1000.');
        }
        $this->pack = $pack;
    }

    private function setThinner(?string $thinner): void
    {
        AssertService::maxLength($thinner, 100);
        $this->thinner = $thinner;
    }
}
```

- [ ] **Step 4: Обновить ORM mapping**

`app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml` — изменить две строки `dry_to_touch`/`full_cure` (заменить `type="float"` на `type="drying_time_series"`):

```xml
<field name="dryToTouch" column="dry_to_touch" type="drying_time_series" nullable="true"/>
<field name="fullCure" column="full_cure" type="drying_time_series" nullable="true"/>
```

Полей `min_dft/max_dft/tds_dft` в маппинге **не было** (они были оставлены без явного описания); добавим embedded для `dftRange` (упрощённый вариант — отдельные columns без префикса):

```xml
<embedded name="dftRange" class="App\Coatings\Domain\Aggregate\Coating\DftRange" use-column-prefix="false"/>
```

**ВАЖНО:** если embedded для nested VO (DftRange→PositiveNumberRange) не заработает на схеме (известный нюанс Doctrine), резервный вариант — заменить embedded на отдельный DBAL type `dft_range` по аналогии с `drying_time_series` (тогда DftRange сериализуется в `JSONB`). Зафиксировать выбранный вариант здесь:

```
ВЫБРАНО: embedded | dft_range JSONB
```

(см. Open Question 1 в спеке.)

- [ ] **Step 5: Запустить unit-тесты Coating**

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php
```

Ожидаемый: все тесты OK.

- [ ] **Step 6: Проверить schema sync (не должно быть критичных diff)**

```bash
docker-compose exec manager_php-cli php bin/console doctrine:schema:validate
```

Если найдены критичные расхождения — допилить orm.xml или сгенерировать дополнительную миграцию.

- [ ] **Step 7: Коммит**

```bash
git add app/src/Coatings/Domain/Aggregate/Coating/Coating.php \
        app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml \
        app/tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php
git commit -m "refactor. coating uses VOs and change methods with assertInvariants"
```

---

### Task 8: CoatingFactory обновление

**Files:**
- Modify: `app/src/Coatings/Domain/Factory/CoatingFactory.php`

- [ ] **Step 1: Обновить сигнатуру `create()`**

`app/src/Coatings/Domain/Factory/CoatingFactory.php`:

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Domain\Factory;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;

readonly class CoatingFactory
{
    public function __construct(private CoatingSpecification $coatingSpecification)
    {
    }

    public function create(
        string           $title,
        string           $description,
        int              $volumeSolid,
        float            $massDensity,
        DftRange         $dftRange,
        int              $applicationMinTemp,
        DryingTimeSeries $dryToTouch,
        float            $minRecoatingInterval,
        float            $maxRecoatingInterval,
        DryingTimeSeries $fullCure,
        Manufacturer     $manufacturer,
        float            $pack,
        ?string          $thinner,
    ): Coating {
        return new Coating(
            $title,
            $description,
            $volumeSolid,
            $massDensity,
            $dftRange,
            $applicationMinTemp,
            $dryToTouch,
            $minRecoatingInterval,
            $maxRecoatingInterval,
            $fullCure,
            $pack,
            $thinner,
            $manufacturer,
            $this->coatingSpecification,
        );
    }
}
```

- [ ] **Step 2: Запустить весь unit-test suite**

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/Unit
```

Если есть падающие — править callsites Factory в тестах. Ожидаемый: OK.

- [ ] **Step 3: Коммит**

```bash
git add app/src/Coatings/Domain/Factory/CoatingFactory.php
git commit -m "refactor. coating factory accepts VOs"
```

---

### Task 9: CoatingDTO + Transformer

**Files:**
- Modify: `app/src/Coatings/Application/DTO/Coatings/CoatingDTO.php`
- Modify: `app/src/Coatings/Application/DTO/Coatings/CoatingDTOTransformer.php`

- [ ] **Step 1: Прочитать текущий CoatingDTO**

```bash
cat app/src/Coatings/Application/DTO/Coatings/CoatingDTO.php
```

Запомнить структуру (поля, типы) — это база для модификации.

- [ ] **Step 2: Заменить float-поля профилей на массивы**

В `CoatingDTO.php` поменять:

```php
// было: public ?float $dryToTouch = null;
public ?array $dryToTouch = null; // [{celsius, minutes}, ...]

// было: public ?float $fullCure = null;
public ?array $fullCure = null;

// убрать (если есть): public ?int $tdsDft, public ?int $minDft, public ?int $maxDft;
public ?array $dftRange = null; // {min, tds, max, type?}
```

Остальные float-поля (`massDensity`, `minRecoatingInterval`, `maxRecoatingInterval`, `pack`) — без изменений.

- [ ] **Step 3: Обновить CoatingDTOTransformer**

В методе, который превращает Coating → DTO:

```php
$dto = new CoatingDTO();
$dto->title = $coating->getTitle();
// ... остальные простые поля
$dto->dryToTouch = $coating->getDryToTouch()->jsonSerialize();
$dto->fullCure = $coating->getFullCure()->jsonSerialize();
$dto->dftRange = [
    'min' => $coating->getDftRange()->range->getMin(),
    'tds' => $coating->getDftRange()->tdsDft,
    'max' => $coating->getDftRange()->range->getMax(),
    'type' => $coating->getDftRange()->type->value,
];
return $dto;
```

- [ ] **Step 4: Запустить тесты, которые касаются DTO/Transformer**

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/
```

Ожидаемый: OK.

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Application/DTO/Coatings/
git commit -m "refactor. coating dto carries vo-shaped data"
```

---

### Task 10: CoatingMapper обновление

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php`

- [ ] **Step 1: Прочитать текущий mapper**

```bash
cat app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php
```

- [ ] **Step 2: Добавить хелперы для сборки VO из массивов DTO**

В `CoatingMapper.php` (или новый сервис рядом — на усмотрение):

```php
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;

private function buildDryingTimeSeries(array $raw): DryingTimeSeries
{
    $points = array_map(
        fn(array $p) => new TimeAtTemperature((int) $p['celsius'], (float) $p['minutes']),
        $raw
    );
    return new DryingTimeSeries($points);
}

private function buildDftRange(array $raw): DftRange
{
    $type = isset($raw['type']) ? ThicknessType::from($raw['type']) : ThicknessType::MIC;
    return new DftRange(
        new PositiveNumberRange((int) $raw['min'], (int) $raw['max']),
        (int) $raw['tds'],
        $type,
    );
}
```

В методах, где собирается `Coating` (через factory), заменить float-поля на вызовы этих хелперов.

- [ ] **Step 3: Запустить юнит-тесты Mapper (если они есть)**

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/
```

- [ ] **Step 4: Коммит**

```bash
git add app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php
git commit -m "refactor. coating mapper builds vos from dto arrays"
```

---

### Task 11: UpdateCoatingCommandHandler

**Files:**
- Modify: `app/src/Coatings/Application/UseCase/Command/UpdateCoating/UpdateCoatingCommandHandler.php`

- [ ] **Step 1: Полная переделка под новый API**

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateCoating;

use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Coatings\Domain\Service\CoatingTagFetcher;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;

readonly class UpdateCoatingCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface      $coatingRepository,
        private ManufacturerRepositoryInterface $manufacturerRepository,
        private CoatingTagFetcher               $coatingTagFetcher,
    ) {}

    public function __invoke(UpdateCoatingCommand $command): UpdateCoatingCommandResult
    {
        $coating = $this->coatingRepository->findOneById($command->coatingId);
        $dto = $command->coatingDTO;

        if ($dto->manufacturer) {
            $coating->changeManufacturer(
                $this->manufacturerRepository->findOneById($dto->manufacturer->id)
            );
        }
        if ($dto->title)       { $coating->changeTitle($dto->title); }
        if ($dto->description) { $coating->changeDescription($dto->description); }

        if ($dto->dryToTouch) {
            $coating->changeDryToTouch($this->buildSeries($dto->dryToTouch));
        }
        if ($dto->fullCure) {
            $coating->changeFullCure($this->buildSeries($dto->fullCure));
        }
        if ($dto->dftRange) {
            $coating->changeDftRange($this->buildDftRange($dto->dftRange));
        }

        if ($dto->volumeSolid)         { $coating->changeVolumeSolid($dto->volumeSolid); }
        if ($dto->massDensity)         { $coating->changeMassDensity($dto->massDensity); }
        if ($dto->applicationMinTemp !== null) { $coating->changeApplicationMinTemp($dto->applicationMinTemp); }
        if ($dto->pack)                { $coating->changePack($dto->pack); }

        // Кросс-полевой интервал — обновляем оба, потом инвариант сработает
        if ($dto->minRecoatingInterval !== null && $dto->maxRecoatingInterval !== null) {
            // Сначала повышаем max, потом устанавливаем min — чтобы не нарушить инвариант посередине
            if ($dto->maxRecoatingInterval > $coating->getMaxRecoatingInterval()) {
                $coating->changeMaxRecoatingInterval($dto->maxRecoatingInterval);
                $coating->changeMinRecoatingInterval($dto->minRecoatingInterval);
            } else {
                $coating->changeMinRecoatingInterval($dto->minRecoatingInterval);
                $coating->changeMaxRecoatingInterval($dto->maxRecoatingInterval);
            }
        } elseif ($dto->minRecoatingInterval !== null) {
            $coating->changeMinRecoatingInterval($dto->minRecoatingInterval);
        } elseif ($dto->maxRecoatingInterval !== null) {
            $coating->changeMaxRecoatingInterval($dto->maxRecoatingInterval);
        }

        $coating->changeThinner($dto->thinner ?? null);

        if ($dto->tags) {
            $tags = [];
            foreach ($dto->tags as $coatingTagDTO) {
                $tags[] = $this->coatingTagFetcher->getRequiredTag($coatingTagDTO->id);
            }
            $coating->replaceTags($tags);
        }

        $this->coatingRepository->add($coating);

        return new UpdateCoatingCommandResult();
    }

    private function buildSeries(array $raw): DryingTimeSeries
    {
        $points = array_map(
            fn(array $p) => new TimeAtTemperature((int) $p['celsius'], (float) $p['minutes']),
            $raw
        );
        return new DryingTimeSeries($points);
    }

    private function buildDftRange(array $raw): DftRange
    {
        $type = isset($raw['type']) ? ThicknessType::from($raw['type']) : ThicknessType::MIC;
        return new DftRange(
            new PositiveNumberRange((int) $raw['min'], (int) $raw['max']),
            (int) $raw['tds'],
            $type,
        );
    }
}
```

- [ ] **Step 2: Запустить функциональные тесты — обновление покрытия**

Если есть функциональный тест на обновление — запустить. Если нет — пропустить:

```bash
docker-compose exec manager_php-cli vendor/bin/phpunit tests/Functional
```

- [ ] **Step 3: Smoke-test через CLI**

```bash
docker-compose exec manager_php-cli php bin/console doctrine:query:sql "SELECT count(*) FROM coatings_coating"
```

Ожидаемый: число > 0, без ошибок гидрации.

- [ ] **Step 4: Коммит**

```bash
git add app/src/Coatings/Application/UseCase/Command/UpdateCoating/UpdateCoatingCommandHandler.php
git commit -m "refactor. update coating handler uses change methods and builds vos"
```

---

### Task 12: Smoke test приложения

**Files:** нет изменений — только проверка.

- [ ] **Step 1: Загрузить приложение в браузере**

Открыть http://localhost:6878 и проверить, что приложение не падает. Логи nginx и php-fpm — без ошибок гидрации/маппинга.

- [ ] **Step 2: Получить список покрытий через API/UI**

Список должен открываться, поля dryToTouch/fullCure возвращаться как массив структур, dftRange как структура.

- [ ] **Step 3: Если на этапе появятся проблемы с embedded DftRange**

Применить резервный план — заменить embedded на отдельный DBAL Type `DftRangeType` (по аналогии с `DryingTimeSeriesType`). Это даёт колонку `dft_range JSONB` вместо нескольких отдельных колонок.

Если резервный план активирован — добавить миграцию `ALTER TABLE coatings_coating ADD COLUMN dft_range JSONB; UPDATE ... ; DROP COLUMN min_dft, max_dft, tds_dft`.

- [ ] **Step 4: Финальный коммит-метка**

```bash
git commit --allow-empty -m "chore. coating vo refactor complete"
```

---

## Self-Review (выполнено)

**Spec coverage:**
- Series + SeriesPoint каркас — Task 1, 2
- TimeAtTemperature — Task 3
- DryingTimeSeries — Task 4
- DBAL type + регистрация — Task 5
- Хранение в JSONB + миграция данных — Task 6
- Coating refactor (поля, бизнес-методы, assertInvariants) — Task 7
- ORM mapping — Task 7
- CoatingFactory — Task 8
- DTO + Transformer — Task 9
- CoatingMapper — Task 10
- UpdateCoatingCommandHandler — Task 11
- Smoke test — Task 12
- Тесты для каждой VO и для Coating инвариантов покрыты Task 2, 3, 4, 7

**Placeholders:** просканировано — нет "TBD", "implement later", "add appropriate handling". Каждый шаг содержит конкретный код или конкретную команду.

**Type consistency:**
- `Series`, `SeriesPoint`, `TimeAtTemperature`, `DryingTimeSeries`, `DryingTimeSeriesType` — имена согласованы во всех тасках.
- `changeXxx` методы Coating вызываются с теми же типами, что объявлены в Task 7.
- `getDryToTouch()`/`getFullCure()` возвращают `DryingTimeSeries` — согласовано с использованием в `CoatingDTOTransformer` (`.jsonSerialize()`) и в тестах.
- `DftRange` остался от ранее сделанной работы — в plan'е используется через `new DftRange(new PositiveNumberRange(...), $tdsDft, $type)`.

**Скоуп:**
- UI/формы (frontend) — явно out of scope, отмечено в начале.
- FTS — следующий PR.

**Open question (отмечено в Task 7 Step 4):**
- Embedded mapping для DftRange может потребовать резервного плана (custom DBAL type) — это разрешается на этапе реализации.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-06-06-coating-vo-refactor.md`.

Two execution options:

1. **Subagent-Driven (recommended)** — fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session with checkpoints between tasks.

Which approach?
