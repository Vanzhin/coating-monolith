# Recoating Interval Kinds (N/A vs Unlimited) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** В рамках `TimeAtTemperature` различить три семантических состояния — длительность, «без ограничения», «нет данных» — через простую кодировку `timeInMinutes: ?int` (>0 / 0 / null). Заодно отремонтировать compare/list-rendering и форму ввода recoating-интервалов так, чтобы пользователь различал «производитель сказал — лимита нет» и «нет информации».

**Architecture:** Никаких новых классов в домене. Меняется тип одного поля + два guard'а в конструкторе. Mapper и Builder перестают молча подменять смысл (`dropZeroDurationPointsRecursively` удаляется). Twig-рендер и форма получают по три ветки рендера/ввода. JSON в БД совместим со старым через `?? null` при чтении.

**Tech Stack:** PHP 8.3, Symfony 7, PHPUnit 9.6, Twig 3, Stimulus 3, Bootstrap 5.

## Global Constraints

- Все PHP/Symfony команды запускаются из `app/`. `cd app` один раз в начале сессии.
- Юнит-тесты: `vendor/bin/phpunit tests/Unit/<path>`. Функциональные: `vendor/bin/phpunit tests/Functional/<path>`.
- Asset rebuild после правок Twig/JS: `yarn dev` из `app/`. Hard-reload браузера (`Cmd+Shift+R`).
- Domain-инварианты по CLAUDE.md: бизнес-правила в домене (`TimeAtTemperature`, `DryingTimeSeries`), Application/Infrastructure не валидируют.
- AppException = единственный канал бизнес-ошибок → HTTP 422 → `<div class="alert alert-danger">`.
- Семантика кодировки точки **жёстко**: `timeInMinutes > 0` = duration; `timeInMinutes === 0` = unlimited; `timeInMinutes === null` = N/A. Никаких других интерпретаций.
- В форме для **max**-recoating доступны все 3 состояния. В форме для **min**-recoating и сушек (`dryToTouch`, `fullCure`) состояние unlimited скрыто; domain их теоретически допускает, форма не предлагает.
- Существующие данные в `max_recoating_interval IS NULL` остаются «всё покрытие N/A» (это решение прошлой итерации).
- Pre-existing failing tests `tests/Functional/Users/Infrastructure/Controller/GetMeActionTest` и `GetUserActionTest` — известны, не относятся к этой задаче.

---

### Task 1: Расширить `TimeAtTemperature` до `?int` + обновить его тесты

**Files:**
- Modify: `app/src/Coatings/Domain/Aggregate/Coating/TimeAtTemperature.php`
- Modify: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/TimeAtTemperatureTest.php`

**Interfaces:**
- Consumes: `App\Shared\Infrastructure\Exception\AppException`, `Carbon\CarbonInterval`.
- Produces:
  - `TimeAtTemperature(int $temperatureAt, ?int $timeInMinutes, bool $isCalculated = false)`. Семантика: `null` = N/A; `0` = unlimited; `>0` = duration.
  - Конструктор бросает `AppException` только при `$timeInMinutes < 0` (отрицательные запрещены). `null` и `0` ОК.
  - `getInterval(): ?CarbonInterval` — `null` если `timeInMinutes` `null` или `0`; иначе `CarbonInterval::minutes($timeInMinutes)`.
  - `jsonSerialize(): array{temperature_at: int, time_in_minutes: ?int, is_calculated: bool}`.

- [ ] **Step 1: Обновить юнит-тесты под новую семантику**

Заменить содержимое `app/tests/Unit/Coatings/Domain/Aggregate/Coating/TimeAtTemperatureTest.php` на:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Shared\Infrastructure\Exception\AppException;
use Carbon\CarbonInterval;
use PHPUnit\Framework\TestCase;

final class TimeAtTemperatureTest extends TestCase
{
    public function testDurationPoint(): void
    {
        $point = new TimeAtTemperature(20, 10);
        $this->assertSame(20, $point->temperatureAt);
        $this->assertSame(10, $point->timeInMinutes);
        $this->assertFalse($point->isCalculated);
    }

    public function testUnlimitedPointIsZeroMinutes(): void
    {
        $point = new TimeAtTemperature(20, 0);
        $this->assertSame(0, $point->timeInMinutes);
    }

    public function testUnknownPointIsNullMinutes(): void
    {
        $point = new TimeAtTemperature(20, null);
        $this->assertNull($point->timeInMinutes);
    }

    public function testNegativeMinutesThrow(): void
    {
        $this->expectException(AppException::class);
        new TimeAtTemperature(20, -1);
    }

    public function testNegativeTemperatureIsAllowed(): void
    {
        $point = new TimeAtTemperature(-10, 60);
        $this->assertSame(-10, $point->temperatureAt);
    }

    public function testIsCalculatedFlag(): void
    {
        $point = new TimeAtTemperature(20, 10, isCalculated: true);
        $this->assertTrue($point->isCalculated);
    }

    public function testGetIntervalForDurationReturnsCarbonInterval(): void
    {
        $point = new TimeAtTemperature(20, 150);
        $interval = $point->getInterval();
        $this->assertInstanceOf(CarbonInterval::class, $interval);
        $this->assertSame(150.0, $interval->totalMinutes);
    }

    public function testGetIntervalForUnlimitedReturnsNull(): void
    {
        $point = new TimeAtTemperature(20, 0);
        $this->assertNull($point->getInterval());
    }

    public function testGetIntervalForUnknownReturnsNull(): void
    {
        $point = new TimeAtTemperature(20, null);
        $this->assertNull($point->getInterval());
    }

    public function testJsonSerializeKeepsDurationMinutes(): void
    {
        $point = new TimeAtTemperature(20, 10);
        $this->assertSame(
            ['temperature_at' => 20, 'time_in_minutes' => 10, 'is_calculated' => false],
            $point->jsonSerialize(),
        );
    }

    public function testJsonSerializeKeepsUnlimitedAsZero(): void
    {
        $point = new TimeAtTemperature(20, 0);
        $this->assertSame(
            ['temperature_at' => 20, 'time_in_minutes' => 0, 'is_calculated' => false],
            $point->jsonSerialize(),
        );
    }

    public function testJsonSerializeKeepsUnknownAsNull(): void
    {
        $point = new TimeAtTemperature(20, null);
        $this->assertSame(
            ['temperature_at' => 20, 'time_in_minutes' => null, 'is_calculated' => false],
            $point->jsonSerialize(),
        );
    }
}
```

- [ ] **Step 2: Запустить тесты, убедиться что часть падает**

```bash
vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/TimeAtTemperatureTest.php
```

Expected: FAIL. Конкретно — `testUnlimitedPointIsZeroMinutes`, `testUnknownPointIsNullMinutes`, `testGetIntervalForUnlimitedReturnsNull`, `testGetIntervalForUnknownReturnsNull`, `testJsonSerializeKeepsUnlimitedAsZero`, `testJsonSerializeKeepsUnknownAsNull` падают (старый guard ловит `<= 0` и для null/0).

- [ ] **Step 3: Изменить `TimeAtTemperature`**

Заменить содержимое `app/src/Coatings/Domain/Aggregate/Coating/TimeAtTemperature.php` на:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Infrastructure\Exception\AppException;
use Carbon\CarbonInterval;
use JsonSerializable;

/**
 * Точка серии «температура → длительность».
 *
 * Семантика timeInMinutes:
 *  - > 0 → реальная длительность в минутах.
 *  - 0   → «без ограничения» (явно введено производителем).
 *  - null → «нет данных» (производитель не указал).
 *
 * Конструктор отвергает только отрицательные значения. null/0 — валидны.
 */
final readonly class TimeAtTemperature implements JsonSerializable
{
    public function __construct(
        public int $temperatureAt,
        public ?int $timeInMinutes,
        public bool $isCalculated = false,
    ) {
        if ($timeInMinutes !== null && $timeInMinutes < 0) {
            throw new AppException(sprintf(
                'Длительность при +%d °C не может быть отрицательной.',
                $temperatureAt,
            ));
        }
    }

    public function getInterval(): ?CarbonInterval
    {
        if ($this->timeInMinutes === null || $this->timeInMinutes === 0) {
            return null;
        }
        return CarbonInterval::minutes($this->timeInMinutes);
    }

    public function jsonSerialize(): array
    {
        return [
            'temperature_at' => $this->temperatureAt,
            'time_in_minutes' => $this->timeInMinutes,
            'is_calculated' => $this->isCalculated,
        ];
    }
}
```

- [ ] **Step 4: Запустить тесты — должны пройти**

```bash
vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/TimeAtTemperatureTest.php
```

Expected: `OK (12 tests, ≥12 assertions)`.

- [ ] **Step 5: Прогнать весь юнит-набор — увидеть какие тесты сломались на смежных типах**

```bash
vendor/bin/phpunit tests/Unit
```

Expected: НЕ зелёные. Падают тесты `DryingTimeSeriesTest`, `RecoatingTreeBuilderTest`, `CoatingTest` и т.п. — все, что строили `TimeAtTemperature(temp, 0)` ожидая исключение. Это нормально — последующие задачи их адаптируют.

- [ ] **Step 6: Commit checkpoint**

Suggested message: `feat(coating): allow null/0 timeInMinutes in TimeAtTemperature (N/A, unlimited)`.

---

### Task 2: Обновить `DryingTimeSeries` (валидация + интерполяция учитывают null/0)

**Files:**
- Modify: `app/src/Coatings/Domain/Aggregate/Coating/DryingTimeSeries.php`
- Modify: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php`

**Interfaces:**
- Consumes: `TimeAtTemperature` из Task 1 (`?int $timeInMinutes`).
- Produces:
  - `DryingTimeSeries::__construct(TimeAtTemperature ...$points)` — без изменений сигнатуры. Серия из 0 точек по-прежнему запрещена.
  - `validatePointsConsistency` — физ-правило «время уменьшается с ростом температуры» применяется ТОЛЬКО к точкам с `timeInMinutes > 0`. Точки `null`/`0` пропускаются.
  - `fromArray` — читает `time_in_minutes` как `?int`: `isset($row['time_in_minutes']) ? (int) $row['time_in_minutes'] : null`. (Если ключ есть со значением `null` — `isset` вернёт false, что нам и нужно: это N/A-точка.)
  - `getPoint(int $t): ?TimeAtTemperature`:
    1. Если есть точка с `temperatureAt === $t` — вернуть **как есть** (включая null/0).
    2. Иначе — `findBoundingPoints` ищет lower/upper **только среди точек с `timeInMinutes > 0`**. Если найдены оба — линейная интерполяция как сейчас, `isCalculated=true`. Иначе — `null`.

- [ ] **Step 1: Прочитать существующий тест `DryingTimeSeriesTest`**

Read `app/tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php`. Запомнить структуру существующих тестов — будем добавлять новые, существующие НЕ удалять.

- [ ] **Step 2: Добавить новые тесты для null/0 точек**

В конец класса `DryingTimeSeriesTest` (перед закрывающей `}`) добавить:

```php
    public function testMixedSeriesWithUnlimitedAndUnknownIsValid(): void
    {
        // Серия: 10°C → 24h (duration), 20°C → null (N/A), 30°C → 12h (duration), 40°C → 0 (unlimited).
        // Физ-правило применяется только к Duration: 24h@10 → 12h@30 — ОК.
        $series = new DryingTimeSeries(
            new TimeAtTemperature(10, 24 * 60),
            new TimeAtTemperature(20, null),
            new TimeAtTemperature(30, 12 * 60),
            new TimeAtTemperature(40, 0),
        );
        $this->assertCount(4, $series->points);
    }

    public function testPhysRuleStillEnforcedAmongDurationPoints(): void
    {
        // Среди Duration: 10°C → 60min, 30°C → 120min — нарушение (выросло с температурой).
        $this->expectException(AppException::class);
        new DryingTimeSeries(
            new TimeAtTemperature(10, 60),
            new TimeAtTemperature(20, null),
            new TimeAtTemperature(30, 120),
        );
    }

    public function testGetPointExactMatchReturnsUnknownAsIs(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(10, 24 * 60),
            new TimeAtTemperature(20, null),
            new TimeAtTemperature(30, 12 * 60),
        );
        $p = $series->getPoint(20);
        $this->assertNotNull($p);
        $this->assertSame(20, $p->temperatureAt);
        $this->assertNull($p->timeInMinutes);
    }

    public function testGetPointExactMatchReturnsUnlimitedAsIs(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(10, 24 * 60),
            new TimeAtTemperature(40, 0),
        );
        $p = $series->getPoint(40);
        $this->assertNotNull($p);
        $this->assertSame(40, $p->temperatureAt);
        $this->assertSame(0, $p->timeInMinutes);
    }

    public function testGetPointInterpolatesAcrossNonDurationPoint(): void
    {
        // 10°C → 24h = 1440min, 20°C → null, 30°C → 12h = 720min.
        // Запрос 15°C: интерполяция между 10 и 30 (null в 20 пропускается).
        // Линейная: 1440 + (720-1440) * (15-10) / (30-10) = 1440 - 720*5/20 = 1440 - 180 = 1260min.
        $series = new DryingTimeSeries(
            new TimeAtTemperature(10, 1440),
            new TimeAtTemperature(20, null),
            new TimeAtTemperature(30, 720),
        );
        $p = $series->getPoint(15);
        $this->assertNotNull($p);
        $this->assertSame(15, $p->temperatureAt);
        $this->assertSame(1260, $p->timeInMinutes);
        $this->assertTrue($p->isCalculated);
    }

    public function testGetPointReturnsNullWhenUpperBoundIsUnlimited(): void
    {
        // 10°C → 24h, 40°C → 0 (unlimited). Запрос 30°C → upper Duration нет → null.
        $series = new DryingTimeSeries(
            new TimeAtTemperature(10, 1440),
            new TimeAtTemperature(40, 0),
        );
        $this->assertNull($series->getPoint(30));
    }

    public function testGetPointReturnsNullWhenLowerBoundMissing(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 1440),
            new TimeAtTemperature(30, 720),
        );
        $this->assertNull($series->getPoint(5));
    }

    public function testFromArrayWithNullMinutes(): void
    {
        $series = DryingTimeSeries::fromArray([
            ['temperature_at' => 10, 'time_in_minutes' => 1440],
            ['temperature_at' => 20, 'time_in_minutes' => null],
            ['temperature_at' => 30, 'time_in_minutes' => 0],
        ]);
        $this->assertCount(3, $series->points);
        $this->assertSame(1440, $series->points[0]->timeInMinutes);
        $this->assertNull($series->points[1]->timeInMinutes);
        $this->assertSame(0, $series->points[2]->timeInMinutes);
    }

    public function testJsonSerializeRoundtripPreservesAllKinds(): void
    {
        $original = new DryingTimeSeries(
            new TimeAtTemperature(10, 1440),
            new TimeAtTemperature(20, null),
            new TimeAtTemperature(30, 0),
        );
        $serialized = json_decode(json_encode($original->jsonSerialize()), true);
        $restored = DryingTimeSeries::fromArray($serialized);

        $this->assertSame(1440, $restored->points[0]->timeInMinutes);
        $this->assertNull($restored->points[1]->timeInMinutes);
        $this->assertSame(0, $restored->points[2]->timeInMinutes);
    }
```

- [ ] **Step 3: Запустить тесты — новые должны падать, старые на консистентность тоже могут**

```bash
vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php
```

Expected: новые тесты падают (старая `validatePointsConsistency` пытается сравнить null/0 как обычные числа), `fromArray` падает на `(int) null`.

- [ ] **Step 4: Поправить `DryingTimeSeries`**

В `app/src/Coatings/Domain/Aggregate/Coating/DryingTimeSeries.php`:

Заменить метод `fromArray`:

```php
public static function fromArray(array $rows): self
{
    $points = array_map(
        fn(array $row): TimeAtTemperature => new TimeAtTemperature(
            (int) $row['temperature_at'],
            array_key_exists('time_in_minutes', $row) ? self::asNullableInt($row['time_in_minutes']) : null,
            (bool) ($row['is_calculated'] ?? false),
        ),
        $rows,
    );

    return new self(...$points);
}

private static function asNullableInt(mixed $v): ?int
{
    return $v === null ? null : (int) $v;
}
```

(Использован `array_key_exists` вместо `isset`, чтобы корректно обработать ключ со значением `null` — `isset` его пропускает.)

Заменить метод `validatePointsConsistency`:

```php
private function validatePointsConsistency(array $points): void
{
    /** @var TimeAtTemperature|null $previous */
    $previous = null;

    foreach ($points as $point) {
        if ($previous !== null) {
            // 1. Дубликат температуры запрещён для любых kind'ов.
            if ($point->temperatureAt === $previous->temperatureAt) {
                throw new AppException(sprintf(
                    'Дублирующаяся температурная точка %d °C.',
                    $point->temperatureAt,
                ));
            }
        }

        // 2. Физ-правило применяем ТОЛЬКО среди Duration-точек.
        // Unlimited (0) и Unknown (null) — не имеют сравнимого числа.
        if ($point->timeInMinutes !== null && $point->timeInMinutes > 0) {
            if ($previous !== null
                && $previous->timeInMinutes !== null
                && $previous->timeInMinutes > 0
                && $point->timeInMinutes > $previous->timeInMinutes
            ) {
                throw new AppException(sprintf(
                    'При +%d °C время сушки (%s) не может быть больше, чем при +%d °C (%s).',
                    $point->temperatureAt,
                    $this->humanize($point->timeInMinutes),
                    $previous->temperatureAt,
                    $this->humanize($previous->timeInMinutes),
                ));
            }
        }

        $previous = $point;
    }
}
```

Заменить метод `findBoundingPoints`:

```php
private function findBoundingPoints(int $key): array
{
    $lower = null;
    $upper = null;

    foreach ($this->points as $point) {
        // Для интерполяции учитываем только Duration-точки.
        // Unlimited (0) и Unknown (null) — пропускаем: между ними и Duration интерполировать нельзя.
        if ($point->timeInMinutes === null || $point->timeInMinutes === 0) {
            continue;
        }
        if ($point->temperatureAt <= $key) {
            $lower = $point;
        }
        if ($point->temperatureAt >= $key) {
            $upper = $point;
            break;
        }
    }

    return [$lower, $upper];
}
```

(Метод `getPoint` сам по себе НЕ меняется — он сначала ищет точное совпадение в `$this->points`, и только потом зовёт `findBoundingPoints`. Точное совпадение с null/0-точкой вернётся «как есть» через первый цикл.)

- [ ] **Step 5: Запустить тесты — должны пройти**

```bash
vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php
```

Expected: все зелёные (старые + 9 новых).

- [ ] **Step 6: Запустить весь юнит-набор**

```bash
vendor/bin/phpunit tests/Unit
```

Expected: всё ещё могут быть падения в `RecoatingTreeBuilderTest`, `CoatingTest`, `RecoatingIntervalTreeTest`, `CoatingDTOTransformerTest` — это нормально, их адаптируют дальнейшие задачи. Главное — DryingTimeSeries и TimeAtTemperature тесты зелёные.

- [ ] **Step 7: Commit checkpoint**

Suggested message: `feat(coating): DryingTimeSeries treats null/0 points as non-Duration (skip phys rule, skip interpolation)`.

---

### Task 3: Обновить `DryingTimePointDTO` + `CoatingMapper` + удалить `dropZeroDurationPointsRecursively`

**Files:**
- Modify: `app/src/Coatings/Application/DTO/Coatings/DryingTimePointDTO.php`
- Modify: `app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php`
- Modify: `app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingMapperTest.php`

**Interfaces:**
- Consumes: ничего нового.
- Produces:
  - `DryingTimePointDTO::$time_in_minutes` — теперь `?int`.
  - `CoatingMapper::buildPointsFromInput(array $rawPoints): array<DryingTimePointDTO>` — читает форму с новым полем `kind`:
    - `kind === 'duration'` → `time_in_minutes = parseDurationInput($row)` (как сейчас). Если результат `0` (юзер ничего не ввёл) — `time_in_minutes = null` (трактуем как N/A).
    - `kind === 'unlimited'` → `time_in_minutes = 0`.
    - `kind === 'unknown'` → `time_in_minutes = null`.
    - **legacy** (нет ключа `kind`): сохраняем текущее поведение — `parseDurationInput`. Если `0` → `null` (не подменяем семантику на unlimited).
  - `CoatingMapper::decomposeSeriesForForm` — для каждой точки в форме добавляется `kind`: `null → 'unknown'`, `0 → 'unlimited'`, `>0 → 'duration'`.
  - Метод `dropZeroDurationPointsRecursively` и его вызов в `buildCoatingDtoFromInputData` **удалены полностью**.

- [ ] **Step 1: Прочитать существующий `CoatingMapperTest`**

Read `app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingMapperTest.php`. Зафиксировать какие тесты сейчас опираются на `dropZeroDurationPointsRecursively` (вероятно проверяют что 0/0/0 max-точки выкидываются).

- [ ] **Step 2: Изменить `DryingTimePointDTO`**

Заменить содержимое `app/src/Coatings/Application/DTO/Coatings/DryingTimePointDTO.php` на:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

class DryingTimePointDTO
{
    public int $temperature_at;
    /** null = N/A; 0 = unlimited; >0 = duration в минутах. */
    public ?int $time_in_minutes = null;
    public bool $is_calculated = false;
}
```

- [ ] **Step 3: В `CoatingMapper` удалить `dropZeroDurationPointsRecursively` и вызов**

В `app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php`:

Найти метод `buildCoatingDtoFromInputData` (около строки 75–80). Заменить блок:

```php
        $dto->minRecoatingInterval = $this->buildTreeDtoFromInput($inputData['minRecoatingInterval'] ?? []);
        // max: 0-длительность в форме означает «без верхней границы при этой температуре» — отбрасываем
        // явно перед маппингом, чтобы домен не отверг такие точки как невалидные.
        $maxRaw = $this->dropZeroDurationPointsRecursively($inputData['maxRecoatingInterval'] ?? []);
        $maxNode = $this->buildTreeDtoFromInput($maxRaw);
        $dto->maxRecoatingInterval = $this->isTreeDtoEffectivelyEmpty($maxNode) ? null : $maxNode;
```

На:

```php
        $dto->minRecoatingInterval = $this->buildTreeDtoFromInput($inputData['minRecoatingInterval'] ?? []);
        // max: точки несут kind = duration/unlimited/unknown. Mapper передаёт всё в домен без фильтрации;
        // domain различает три состояния через ?int $timeInMinutes.
        $maxNode = $this->buildTreeDtoFromInput($inputData['maxRecoatingInterval'] ?? []);
        $dto->maxRecoatingInterval = $this->isTreeDtoEffectivelyEmpty($maxNode) ? null : $maxNode;
```

Удалить целиком private метод `dropZeroDurationPointsRecursively` (с его doc-блоком).

- [ ] **Step 4: В `CoatingMapper` обновить `buildPointsFromInput`**

Найти метод `buildPointsFromInput`. Заменить его реализацию на:

```php
    private function buildPointsFromInput(array $rawPoints): array
    {
        return array_values(array_map(function (array $raw): DryingTimePointDTO {
            $point = new DryingTimePointDTO();
            $point->temperature_at = (int) ($raw['temperature_at'] ?? 20);
            $point->time_in_minutes = $this->resolveTimeInMinutes($raw);
            $point->is_calculated = (bool) ($raw['is_calculated'] ?? false);
            return $point;
        }, $rawPoints));
    }

    /**
     * Резолвит time_in_minutes из формы с учётом kind:
     *  - kind = 'duration' → парсим days/hours/minutes; 0 → null (юзер не ввёл).
     *  - kind = 'unlimited' → 0.
     *  - kind = 'unknown' → null.
     *  - kind отсутствует (legacy / старый формат): парсим как duration; 0 → null.
     */
    private function resolveTimeInMinutes(array $raw): ?int
    {
        $kind = $raw['kind'] ?? null;

        if ($kind === 'unlimited') {
            return 0;
        }
        if ($kind === 'unknown') {
            return null;
        }

        // duration (явный или legacy)
        if (isset($raw['time_in_minutes']) && $raw['time_in_minutes'] !== '') {
            $value = (int) $raw['time_in_minutes'];
            return $value === 0 ? null : $value;
        }
        $value = $this->parseDurationInput($raw);
        return $value === 0 ? null : $value;
    }
```

- [ ] **Step 5: В `CoatingMapper` обновить `decomposeSeriesForForm`**

Найти метод `decomposeSeriesForForm`. Заменить его на:

```php
    /**
     * @param ?list<DryingTimePointDTO> $points null = весь max-tree отсутствует (старая семантика).
     * @return list<array<string, mixed>>
     */
    private function decomposeSeriesForForm(?array $points): array
    {
        if ($points === null) {
            return [];
        }
        return array_map(
            fn(DryingTimePointDTO $p) => array_merge(
                $this->decomposeDurationForForm($p->time_in_minutes ?? 0),
                [
                    'temperature_at' => $p->temperature_at,
                    'time_in_minutes' => $p->time_in_minutes,
                    'is_calculated' => $p->is_calculated,
                    'kind' => $this->kindForMinutes($p->time_in_minutes),
                ],
            ),
            $points,
        );
    }

    private function kindForMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return 'unknown';
        }
        if ($minutes === 0) {
            return 'unlimited';
        }
        return 'duration';
    }
```

- [ ] **Step 6: В `CoatingMapper::getValidationCollectionCoating` (точнее в helper `seriesFieldConstraints`) добавить разрешение поля `kind`**

Найти метод `seriesFieldConstraints` (около строки 280–296). Внутри `Assert\Collection.fields` добавить ключ `kind`:

```php
                    'kind' => new Assert\Optional([
                        new Assert\Choice(['duration', 'unlimited', 'unknown']),
                    ]),
```

Полная новая конструкция должна выглядеть так:

```php
            new Assert\Collection([
                'fields' => [
                    'temperature_at'  => [new Assert\NotBlank(), new Assert\Type('numeric')],
                    'days'            => new Assert\Optional([new Assert\Type('numeric')]),
                    'hours'           => new Assert\Optional([new Assert\Type('numeric')]),
                    'minutes'         => new Assert\Optional([new Assert\Type('numeric')]),
                    'time_in_minutes' => new Assert\Optional([new Assert\Type('numeric')]),
                    'is_calculated'   => new Assert\Optional([new Assert\Type('numeric')]),
                    'kind'            => new Assert\Optional([new Assert\Choice(['duration', 'unlimited', 'unknown'])]),
                ],
                'allowExtraFields' => true,
            ]),
```

- [ ] **Step 7: Обновить `CoatingMapperTest`**

Read `app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingMapperTest.php`. Найти любой тест, который проверяет что 0/0/0 max-точки выкидываются (поиск по тексту `dropZero`, `без верхней`, `0/0/0`, или похожим). Удалить такой тест.

В конец того же файла (перед закрывающей `}`) добавить новые:

```php
    public function testBuildsUnlimitedFromKindAttribute(): void
    {
        $mapper = $this->makeMapper();
        $input = $this->minimalInput([
            'maxRecoatingInterval' => [
                'default' => ['points' => [[
                    'temperature_at' => 20,
                    'kind' => 'unlimited',
                    'days' => 0, 'hours' => 0, 'minutes' => 0,
                ]]],
                'branches' => [],
            ],
        ]);
        $manufacturer = $this->makeManufacturerDto();

        $dto = $mapper->buildCoatingDtoFromInputData($input, $manufacturer);

        $this->assertNotNull($dto->maxRecoatingInterval);
        $this->assertCount(1, $dto->maxRecoatingInterval->default);
        $this->assertSame(0, $dto->maxRecoatingInterval->default[0]->time_in_minutes);
    }

    public function testBuildsUnknownFromKindAttribute(): void
    {
        $mapper = $this->makeMapper();
        $input = $this->minimalInput([
            'maxRecoatingInterval' => [
                'default' => ['points' => [[
                    'temperature_at' => 20,
                    'kind' => 'unknown',
                    'days' => 0, 'hours' => 0, 'minutes' => 0,
                ]]],
                'branches' => [],
            ],
        ]);
        $manufacturer = $this->makeManufacturerDto();

        $dto = $mapper->buildCoatingDtoFromInputData($input, $manufacturer);

        $this->assertNotNull($dto->maxRecoatingInterval);
        $this->assertCount(1, $dto->maxRecoatingInterval->default);
        $this->assertNull($dto->maxRecoatingInterval->default[0]->time_in_minutes);
    }

    public function testLegacyZeroDurationBecomesUnknown(): void
    {
        // Без явного kind: дни/часы/минуты все 0 → точка имеет time_in_minutes = null.
        // Это безопасный дефолт для старых форм: «юзер ничего не ввёл, не подменяем на unlimited».
        $mapper = $this->makeMapper();
        $input = $this->minimalInput([
            'maxRecoatingInterval' => [
                'default' => ['points' => [[
                    'temperature_at' => 20,
                    'days' => 0, 'hours' => 0, 'minutes' => 0,
                ]]],
                'branches' => [],
            ],
        ]);
        $manufacturer = $this->makeManufacturerDto();

        $dto = $mapper->buildCoatingDtoFromInputData($input, $manufacturer);

        $this->assertNotNull($dto->maxRecoatingInterval);
        $this->assertCount(1, $dto->maxRecoatingInterval->default);
        $this->assertNull($dto->maxRecoatingInterval->default[0]->time_in_minutes);
    }

    public function testDecomposeAddsKindForFormRoundtrip(): void
    {
        $mapper = $this->makeMapper();

        // Серия из трёх точек разных kind.
        $duration = new DryingTimePointDTO();
        $duration->temperature_at = 10;
        $duration->time_in_minutes = 720;

        $unlimited = new DryingTimePointDTO();
        $unlimited->temperature_at = 20;
        $unlimited->time_in_minutes = 0;

        $unknown = new DryingTimePointDTO();
        $unknown->temperature_at = 30;
        $unknown->time_in_minutes = null;

        $decomposeMethod = new \ReflectionMethod($mapper, 'decomposeSeriesForForm');
        $decomposeMethod->setAccessible(true);
        $form = $decomposeMethod->invoke($mapper, [$duration, $unlimited, $unknown]);

        $this->assertSame('duration', $form[0]['kind']);
        $this->assertSame('unlimited', $form[1]['kind']);
        $this->assertSame('unknown', $form[2]['kind']);
        $this->assertSame(720, $form[0]['time_in_minutes']);
        $this->assertSame(0, $form[1]['time_in_minutes']);
        $this->assertNull($form[2]['time_in_minutes']);
    }
```

Helper-методы `makeMapper`, `minimalInput`, `makeManufacturerDto`, `DryingTimePointDTO` импорт — в файле уже есть (использовались существующими тестами), если в каком-то методе нет — добавить `use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;`.

- [ ] **Step 8: Запустить mapper-тесты**

```bash
vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Mapper/CoatingMapperTest.php
```

Expected: все зелёные (старые остаются + 4 новых).

- [ ] **Step 9: Запустить весь юнит-набор**

```bash
vendor/bin/phpunit tests/Unit
```

Expected: возможно ещё остаются падения в `RecoatingTreeBuilderTest`, `CoatingDTOTransformerTest` (они тоже строят `TimeAtTemperature`). Будут адресованы в Task 4.

- [ ] **Step 10: Commit checkpoint**

Suggested message: `feat(coating): mapper reads kind from form, drops dropZeroDurationPointsRecursively`.

---

### Task 4: Прогнать смежные тесты + адаптировать оставшиеся

**Files:**
- Modify: `app/tests/Unit/Coatings/Application/UseCase/Command/RecoatingTreeBuilderTest.php` (если падает)
- Modify: `app/tests/Unit/Coatings/Application/DTO/Coatings/CoatingDTOTransformerTest.php` (если падает)
- Modify: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php` (если падает)
- Modify: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalTreeTest.php` (если падает)

**Interfaces:**
- Consumes: Task 1–3 (новый `TimeAtTemperature`, `DryingTimeSeries`, `CoatingMapper`).
- Produces: зелёный `tests/Unit`.

Эта задача — компенсация: домен/маппер изменились, существующие тесты могут падать на трёх причинах:
1. `new TimeAtTemperature($t, 0)` ожидал `AppException` — теперь это валидный unlimited.
2. `new TimeAtTemperature($t, null)` нельзя было собрать — теперь можно (валидный unknown).
3. Тесты, опирающиеся на `dropZeroDurationPointsRecursively` (точки выкидывались) — теперь не выкидываются.

- [ ] **Step 1: Запустить весь юнит-набор, собрать список падающих файлов**

```bash
vendor/bin/phpunit tests/Unit 2>&1 | grep -E "^[0-9]+\) " | head -20
```

Записать список упавших тест-методов.

- [ ] **Step 2: Для каждого упавшего теста — поправить**

Прочитать упавший тест. Применить одно из:
- Если тест проверял, что `new TimeAtTemperature($t, 0)` бросает — заменить ожидание на «не бросает» И поправить смысл (что именно проверял). Если проверял именно «нельзя 0» — удалить тест (он зашит в старую инвариантную модель).
- Если тест проверял что 0/0/0 max-точка выкидывается mapper'ом → переписать ассерт: теперь точка сохраняется со значением `time_in_minutes = null` (legacy путь).
- Если тест строит mixed-серию через DryingTimeSeries и проверяет интерполяцию — посмотреть, попадает ли запрашиваемая температура между Duration-точками; если нет — переписать ожидание на null.

Не угадывать «как было задумано» — читать тестируемый код. Если неясно — STOP и эскалируй пользователю.

- [ ] **Step 3: Запустить весь юнит-набор**

```bash
vendor/bin/phpunit tests/Unit
```

Expected: все зелёные.

- [ ] **Step 4: Commit checkpoint**

Suggested message: `test(coating): adapt domain/mapper tests for kind-aware TimeAtTemperature`.

---

### Task 5: Расширить `duration_input.html.twig` + добавить kind-radio в форму

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/components/duration_input.html.twig`

**Interfaces:**
- Consumes: `value` — массив с ключами `days`, `hours`, `minutes`, `time_in_minutes`, `kind` (из `decomposeSeriesForForm` после Task 3).
- Produces: hidden inputs `[days]`, `[hours]`, `[minutes]`, `[kind]` + одна display-кнопка, открывающая модалку. Bool-параметр `allow_unlimited` управляет, есть ли в модалке кнопка «без ограничения».

- [ ] **Step 1: Прочитать форму, понять где макрос вызывается**

Read `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_recoating_node.html.twig` строки 150–160 (там вызывается `duration_input` для min и max — для min `required=true`, для max `required=false`).

Read `app/src/Shared/Infrastructure/Templates/admin/coating/coating/form.html.twig` строки 235 и 278 (там для dryToTouch / fullCure).

Цель: после правок макроса вызывающим кодом макрос принимает дополнительный параметр `allow_unlimited`. Для max-recoating — `true`, для min-recoating и сушек — `false`.

- [ ] **Step 2: Заменить макрос**

Заменить содержимое `app/src/Shared/Infrastructure/Templates/components/duration_input.html.twig` на:

```twig
{# Кликабельный «дисплей-инпут» длительности.
   Параметры:
     name — префикс name= для hidden inputs.
     value — ассоциативный массив с ключами days/hours/minutes/kind/time_in_minutes.
       Семантика kind:
         'duration'  → введена длительность (days/hours/minutes > 0).
         'unlimited' → производитель явно сказал «без ограничения». time_in_minutes = 0.
         'unknown'   → производитель не указал. time_in_minutes = null.
         (если ключ kind отсутствует — определяем по time_in_minutes / days+hours+minutes).
     required — для min-серий и сушек, в модалке не показываем «без ограничения» и «нет данных».
     label — подпись модалки.
     allow_unlimited — true для max-recoating; false — для min-recoating и сушек.

   Открывает общую модалку #durationModal (объявлена в form.html.twig). Stimulus-контроллер
   coating-form подхватывает kind через data-kind / data-allow-unlimited и переключает inputs.
#}
{% macro duration_input(name, value, required=false, label='Длительность', allow_unlimited=false) %}
    {% set days = (value.days ?? 0) %}
    {% set hours = (value.hours ?? 0) %}
    {% set minutes = (value.minutes ?? 0) %}

    {# Resolve kind: явный из value либо по эвристике для legacy-данных. #}
    {% set kind = value.kind|default(null) %}
    {% if kind is null %}
        {% if value.time_in_minutes is defined and value.time_in_minutes is null %}
            {% set kind = 'unknown' %}
        {% elseif value.time_in_minutes is defined and value.time_in_minutes == 0 %}
            {% set kind = required ? 'unknown' : 'unlimited' %}
        {% else %}
            {% set total = days * 1440 + hours * 60 + minutes %}
            {% if total > 0 %}
                {% set kind = 'duration' %}
            {% else %}
                {% set kind = required ? 'unknown' : 'unlimited' %}
            {% endif %}
        {% endif %}
    {% endif %}

    <input type="hidden" name="{{ name }}[days]"    value="{{ kind == 'duration' ? days : 0 }}">
    <input type="hidden" name="{{ name }}[hours]"   value="{{ kind == 'duration' ? hours : 0 }}">
    <input type="hidden" name="{{ name }}[minutes]" value="{{ kind == 'duration' ? minutes : 0 }}">
    <input type="hidden" name="{{ name }}[kind]"    value="{{ kind }}">

    {% set totalMinutes = days * 1440 + hours * 60 + minutes %}

    <button type="button"
            class="btn btn-sm duration-display-btn {{ kind == 'duration' ? 'btn-outline-primary' : 'btn-outline-secondary text-muted' }}"
            data-bs-toggle="modal"
            data-bs-target="#durationModal"
            data-target-name="{{ name }}"
            data-target-label="{{ label }}"
            data-required="{{ required ? '1' : '0' }}"
            data-allow-unlimited="{{ allow_unlimited ? '1' : '0' }}"
            data-current-kind="{{ kind }}">
        {% if kind == 'duration' %}
            {{ totalMinutes|duration_minutes_short }}
        {% elseif kind == 'unlimited' %}
            <i class="bi bi-infinity"></i> без ограничения
        {% else %}
            <i class="bi bi-pencil"></i> нет данных
        {% endif %}
    </button>
{% endmacro %}
```

- [ ] **Step 3: Обновить вызовы макроса в `_recoating_node.html.twig`**

В `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_recoating_node.html.twig` найти две строки 153–154 и заменить на:

```twig
                    <td>{{ duration_input(minPrefix ~ '[' ~ i ~ ']', minRow, true, 'Минимальный интервал перекрытия при +' ~ temp ~ '°C', false) }}</td>
                    <td>{{ duration_input(maxPrefix ~ '[' ~ i ~ ']', maxRow, false, 'Максимальный интервал перекрытия при +' ~ temp ~ '°C', true) }}</td>
```

(Добавлен явный последний аргумент `allow_unlimited`: для min — `false`, для max — `true`.)

- [ ] **Step 4: Обновить вызовы для сушек в `form.html.twig`**

В `app/src/Shared/Infrastructure/Templates/admin/coating/coating/form.html.twig` найти строки с `duration_input('dryToTouch...` и `duration_input('fullCure...` (строки 235 и 278). Добавить последний аргумент `false`:

```twig
                                    <td>{{ duration_input('dryToTouch[' ~ i ~ ']', row, true, 'Сухой на отлип при +' ~ (row.temperature_at ?? 20) ~ '°C', false) }}</td>
```

```twig
                                    <td>{{ duration_input('fullCure[' ~ i ~ ']', row, true, 'Полное отверждение при +' ~ (row.temperature_at ?? 20) ~ '°C', false) }}</td>
```

- [ ] **Step 5: Пересобрать ассеты (Twig автокомпилится, но нужен sanity check)**

```bash
yarn dev
```

Expected: `webpack compiled successfully`. Если упало — Twig-синтаксические ошибки в макросе, читать вывод.

- [ ] **Step 6: Прогнать функциональные тесты**

```bash
vendor/bin/phpunit tests/Functional/Coatings
```

Expected: все зелёные (если красное — Twig крашится при рендере формы, читать вывод).

- [ ] **Step 7: Commit checkpoint**

Suggested message: `feat(coating): duration_input renders 3 kinds (duration/unlimited/unknown)`.

---

### Task 6: Stimulus-контроллер — переключение kind в модалке

**Files:**
- Modify: `app/assets/controllers/coating_form_controller.js`
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/form.html.twig` (содержимое модалки `#durationModal`)

**Interfaces:**
- Consumes: атрибуты на кнопке-триггере (`data-target-name`, `data-required`, `data-allow-unlimited`, `data-current-kind`) — из Task 5.
- Produces:
  - Модалка теперь содержит 3 radio-кнопки (Duration / Unlimited / Unknown). Если `allow_unlimited=0` — radio `unlimited` скрыт. Если `required=1` — radio `unknown` тоже скрыт (для min/сушек обязательно вводить duration).
  - `saveDuration()` записывает `kind` в hidden `[kind]` и сбрасывает days/hours/minutes в 0 для не-duration.
  - При открытии модалки текущий `kind` подсвечивается; если `duration` — поля days/hours/minutes показаны и заполнены; для unlimited/unknown — скрыты.

- [ ] **Step 1: Найти модалку в form.html.twig**

Read `app/src/Shared/Infrastructure/Templates/admin/coating/coating/form.html.twig` — найти блок с `id="durationModal"` (поиск по строке `durationModal`). Прочитать всю модалку (обычно 30–50 строк HTML).

- [ ] **Step 2: Расширить модалку — добавить три radio + переключение видимости**

Внутри `.modal-body` модалки `#durationModal`, перед существующими input'ами `days/hours/minutes`, добавить:

```twig
            <div class="mb-3" data-coating-form-target="kindGroup">
                <div class="btn-group w-100" role="group" aria-label="Тип значения">
                    <input type="radio" class="btn-check" name="durationModalKind" id="durationModalKind-duration"
                           value="duration" data-coating-form-target="kindRadio"
                           data-action="change->coating-form#onKindChange">
                    <label class="btn btn-outline-primary" for="durationModalKind-duration">Длительность</label>

                    <input type="radio" class="btn-check" name="durationModalKind" id="durationModalKind-unlimited"
                           value="unlimited" data-coating-form-target="kindRadio"
                           data-action="change->coating-form#onKindChange">
                    <label class="btn btn-outline-secondary" for="durationModalKind-unlimited" data-coating-form-target="kindUnlimitedLabel">Без ограничения</label>

                    <input type="radio" class="btn-check" name="durationModalKind" id="durationModalKind-unknown"
                           value="unknown" data-coating-form-target="kindRadio"
                           data-action="change->coating-form#onKindChange">
                    <label class="btn btn-outline-secondary" for="durationModalKind-unknown" data-coating-form-target="kindUnknownLabel">Нет данных</label>
                </div>
            </div>

            <div data-coating-form-target="durationFields">
```

И **закрыть** обёртку `<div data-coating-form-target="durationFields">` непосредственно перед существующими кнопками modal-footer (то есть весь блок с days/hours/minutes inputs должен быть внутри этого div).

- [ ] **Step 3: Расширить Stimulus-контроллер**

В `app/assets/controllers/coating_form_controller.js` найти секцию `static targets = [...]` и добавить в массив новые таргеты:

```js
'kindGroup', 'kindRadio', 'kindUnlimitedLabel', 'kindUnknownLabel', 'durationFields',
```

Найти метод, который открывает модалку (по `data-bs-toggle="modal"` модалка открывается Bootstrap'ом сама, контроллер ловит `shown.bs.modal` или похожее). Если такой метод не существует — найти `saveDuration` и рядом добавить логику в `connect()` или новый метод `onShowDurationModal`:

```js
    onShowDurationModal(event) {
        const button = event.relatedTarget;
        if (!button) return;
        this.currentName = button.dataset.targetName;
        const required = button.dataset.required === '1';
        const allowUnlimited = button.dataset.allowUnlimited === '1';
        const currentKind = button.dataset.currentKind || 'duration';

        // Скрываем недоступные radio для данного контекста.
        if (this.hasKindUnlimitedLabelTarget) {
            this.kindUnlimitedLabelTarget.style.display = allowUnlimited ? '' : 'none';
            this.kindUnlimitedLabelTarget.previousElementSibling.style.display = allowUnlimited ? '' : 'none';
        }
        if (this.hasKindUnknownLabelTarget) {
            // Для required (min/сушка) скрываем «нет данных» — должно быть введено duration.
            const showUnknown = !required;
            this.kindUnknownLabelTarget.style.display = showUnknown ? '' : 'none';
            this.kindUnknownLabelTarget.previousElementSibling.style.display = showUnknown ? '' : 'none';
        }

        // Подсветить текущий kind.
        const safeKind = (currentKind === 'unlimited' && allowUnlimited)
            || (currentKind === 'unknown' && !required)
            || currentKind === 'duration'
            ? currentKind
            : 'duration';

        this.kindRadioTargets.forEach(r => {
            r.checked = r.value === safeKind;
        });

        // Подгрузить значения days/hours/minutes из текущей строки.
        this.modalDaysTarget.value    = this._readHidden(this.currentName, 'days');
        this.modalHoursTarget.value   = this._readHidden(this.currentName, 'hours');
        this.modalMinutesTarget.value = this._readHidden(this.currentName, 'minutes');

        this._applyKindVisibility(safeKind);
    }

    onKindChange() {
        const kind = this._currentRadioKind();
        this._applyKindVisibility(kind);
        if (kind !== 'duration') {
            this.modalDaysTarget.value = 0;
            this.modalHoursTarget.value = 0;
            this.modalMinutesTarget.value = 0;
        }
    }

    _applyKindVisibility(kind) {
        if (this.hasDurationFieldsTarget) {
            this.durationFieldsTarget.style.display = kind === 'duration' ? '' : 'none';
        }
    }

    _currentRadioKind() {
        const checked = this.kindRadioTargets.find(r => r.checked);
        return checked ? checked.value : 'duration';
    }

    _readHidden(name, key) {
        const el = this.element.querySelector(`input[type="hidden"][name="${name}[${key}]"]`);
        return el ? el.value : 0;
    }
```

И зарегистрировать слушатель открытия модалки. В `connect()`:

```js
        const modalEl = document.getElementById('durationModal');
        if (modalEl) {
            modalEl.addEventListener('show.bs.modal', this.onShowDurationModal.bind(this));
        }
```

Заменить существующий `saveDuration()`:

```js
    saveDuration() {
        if (!this.currentName) return;

        const kind = this._currentRadioKind();
        const isDuration = kind === 'duration';

        this._hidden(this.currentName, 'days').value    = isDuration ? this._intVal(this.modalDaysTarget) : 0;
        this._hidden(this.currentName, 'hours').value   = isDuration ? this._intVal(this.modalHoursTarget) : 0;
        this._hidden(this.currentName, 'minutes').value = isDuration ? this._intVal(this.modalMinutesTarget) : 0;

        // Хидден поле [kind] должно существовать (создаётся макросом). Если нет — создаём.
        let kindHidden = this._hidden(this.currentName, 'kind');
        if (!kindHidden) {
            kindHidden = document.createElement('input');
            kindHidden.type = 'hidden';
            kindHidden.name = `${this.currentName}[kind]`;
            this.element.querySelector(`button[data-target-name="${this.currentName}"]`)?.parentElement?.appendChild(kindHidden);
        }
        kindHidden.value = kind;

        const btn = this.element.querySelector(`button[data-target-name="${this.currentName}"]`);
        if (btn) {
            btn.dataset.currentKind = kind;
            this._refreshButton(btn);
        }

        Modal.getOrCreateInstance(this.modalTarget).hide();
    }
```

Заменить `_refreshButton(btn)` (метод обновляет внешний вид кнопки). Найти текущую реализацию и заменить на:

```js
    _refreshButton(btn) {
        const kind = btn.dataset.currentKind || 'duration';
        btn.classList.toggle('btn-outline-primary', kind === 'duration');
        btn.classList.toggle('btn-outline-secondary', kind !== 'duration');
        btn.classList.toggle('text-muted', kind !== 'duration');

        if (kind === 'duration') {
            const d = parseInt(this._readHidden(btn.dataset.targetName, 'days'), 10) || 0;
            const h = parseInt(this._readHidden(btn.dataset.targetName, 'hours'), 10) || 0;
            const m = parseInt(this._readHidden(btn.dataset.targetName, 'minutes'), 10) || 0;
            const totalMin = d * 1440 + h * 60 + m;
            btn.innerHTML = this._formatMinutesShort(totalMin);
        } else if (kind === 'unlimited') {
            btn.innerHTML = '<i class="bi bi-infinity"></i> без ограничения';
        } else {
            btn.innerHTML = '<i class="bi bi-pencil"></i> нет данных';
        }
    }

    _formatMinutesShort(totalMinutes) {
        if (totalMinutes <= 0) return '0 мин';
        const d = Math.floor(totalMinutes / 1440);
        const h = Math.floor((totalMinutes - d * 1440) / 60);
        const m = totalMinutes - d * 1440 - h * 60;
        const parts = [];
        if (d) parts.push(`${d} д`);
        if (h) parts.push(`${h} ч`);
        if (m) parts.push(`${m} мин`);
        return parts.length ? parts.join(' ') : '0 мин';
    }
```

(Если в файле уже есть похожий метод `_formatMinutesShort` или импорт — не дублировать.)

- [ ] **Step 4: Пересобрать ассеты**

```bash
yarn dev
```

Expected: `webpack compiled successfully`.

- [ ] **Step 5: Manual smoke check в браузере**

1. Hard-reload форму редактирования покрытия.
2. Открыть строку max-recoating. Кликнуть кнопку «нет данных» → в модалке доступны 3 радио. Выбрать «Без ограничения», Save → кнопка стала «∞ без ограничения». Выбрать «Длительность», ввести 5 дней, Save → кнопка стала «5 д». Выбрать «Нет данных», Save → кнопка вернулась в «нет данных».
3. Открыть кнопку min-recoating. В модалке доступна только «Длительность» (unlimited скрыт, unknown скрыт — т.к. required).
4. Открыть кнопку dryToTouch. Та же ситуация что для min — только Длительность.

- [ ] **Step 6: Commit checkpoint**

Suggested message: `feat(coating): kind selector in durationModal + Stimulus glue`.

---

### Task 7: Compare-page и list-modal — рендер трёх состояний

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/compare.html.twig`
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_recoating_pair_table.html.twig`
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig` (preview-modal)

**Interfaces:**
- Consumes: точка с `time_in_minutes` (`null` / `0` / `>0`).
- Produces: явное визуальное различение «нет данных» vs «без ограничения» в трёх местах рендера.

- [ ] **Step 1: Заменить макрос `format_value` для recoatingInterval в compare.html.twig**

В `app/src/Shared/Infrastructure/Templates/admin/coating/coating/compare.html.twig` найти секцию `{% macro format_value(field, value) %}`. Заменить первую ветку:

```twig
    {% if field == 'maxRecoatingInterval' and value is null %}
        <span class="text-muted">Без верхней границы</span>
```

На (по-прежнему обрабатывает null-tree для всего покрытия):

```twig
    {% if field == 'maxRecoatingInterval' and value is null %}
        <span class="text-muted">— нет данных по всему покрытию</span>
```

Внутри ветки `{% elseif field == 'minRecoatingInterval' or field == 'maxRecoatingInterval' %}` (где вызывается макрос `render_tree`) — НИЧЕГО не менять напрямую, всё в `_recoating_pair_table.html.twig` (см. ниже).

- [ ] **Step 2: Расширить макрос в `_recoating_pair_table.html.twig`**

В `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_recoating_pair_table.html.twig` найти блоки рендера min и max. Заменить «min» рендер (строка с `point.time_in_minutes|duration_minutes`):

```twig
                            <td>
                                {{ point.time_in_minutes|duration_minutes }}
                                {% if point.is_calculated %}
                                    <span class="badge text-bg-warning ms-1" title="Расчётное значение">расчёт</span>
                                {% endif %}
                            </td>
```

На:

```twig
                            <td>
                                {% if point.time_in_minutes is null %}
                                    <span class="text-muted">— нет данных</span>
                                {% elseif point.time_in_minutes == 0 %}
                                    <span class="text-muted"><i class="bi bi-infinity"></i> без ограничения</span>
                                {% else %}
                                    {{ point.time_in_minutes|duration_minutes }}
                                    {% if point.is_calculated %}
                                        <span class="badge text-bg-warning ms-1" title="Расчётное значение">расчёт</span>
                                    {% endif %}
                                {% endif %}
                            </td>
```

Аналогично для «max» рендера (внутри `{% if maxPoint %} ... {% else %} ... {% endif %}`). Заменить блок:

```twig
                                {% if maxPoint %}
                                    {{ maxPoint.time_in_minutes|duration_minutes }}
                                    {% if maxPoint.is_calculated %}
                                        <span class="badge text-bg-warning ms-1" title="Расчётное значение">расчёт</span>
                                    {% endif %}
                                {% else %}
                                    <span class="text-muted">—</span>
                                {% endif %}
```

На:

```twig
                                {% if maxPoint is null %}
                                    <span class="text-muted">—</span>
                                {% elseif maxPoint.time_in_minutes is null %}
                                    <span class="text-muted">— нет данных</span>
                                {% elseif maxPoint.time_in_minutes == 0 %}
                                    <span class="text-muted"><i class="bi bi-infinity"></i> без ограничения</span>
                                {% else %}
                                    {{ maxPoint.time_in_minutes|duration_minutes }}
                                    {% if maxPoint.is_calculated %}
                                        <span class="badge text-bg-warning ms-1" title="Расчётное значение">расчёт</span>
                                    {% endif %}
                                {% endif %}
```

- [ ] **Step 3: Обновить preview-modal в `index.html.twig`**

В `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig` найти строку 267 (`<p class="text-muted small mb-0 mt-2">Без верхней границы.</p>`) и контекст вокруг. Это рендер случая когда `maxRecoatingInterval` целиком null. Заменить на:

```twig
                                            <p class="text-muted small mb-0 mt-2">— нет данных по всему покрытию.</p>
```

(Существующие точечные рендеры внутри preview-modal используют тот же `_recoating_pair_table.html.twig`, который мы уже обновили в Step 2 — никаких дополнительных правок.)

- [ ] **Step 4: Пересобрать ассеты**

```bash
yarn dev
```

Expected: `webpack compiled successfully`.

- [ ] **Step 5: Manual smoke check**

1. Открыть покрытие, у которого `max_recoating_interval IS NULL` в БД (любое из старых) — preview-modal показывает «— нет данных по всему покрытию.».
2. Создать новое покрытие с max=unlimited при +20°C и max=N/A при +40°C. Открыть preview — две точки с разными подписями.
3. Compare этого покрытия с другим — те же подписи.

- [ ] **Step 6: Прогнать функциональные тесты**

```bash
vendor/bin/phpunit tests/Functional/Coatings
```

Expected: все зелёные.

- [ ] **Step 7: Commit checkpoint**

Suggested message: `feat(coating): compare/list/preview distinguish N/A from unlimited in render`.

---

### Task 8: Functional-тест на пользовательский сценарий

**Files:**
- Modify: `app/tests/Functional/Coatings/Infrastructure/Controller/Coating/UpdateActionRecoatingTreeTest.php` (добавить новый тест в существующий класс)

**Interfaces:**
- Consumes: вся инфра Task 1–7.
- Produces: воспроизведение конкретного пользовательского сценария, который раньше падал с `AppException`.

- [ ] **Step 1: Прочитать существующий `UpdateActionRecoatingTreeTest`**

Read `app/tests/Functional/Coatings/Infrastructure/Controller/Coating/UpdateActionRecoatingTreeTest.php` — изучить `setUp` (создание юзера + manufacturer + coating).

- [ ] **Step 2: Добавить новый тест в конец класса**

Перед закрывающей `}` класса добавить:

```php
    public function testSubmittingMaxTreeWithRootUnknownAndChildrenSetIsAccepted(): void
    {
        // Сценарий из жалобы пользователя:
        //  - root.max: для +35°C → нет данных (unknown)
        //  - immersion.default.max: для +20°C → 12 дней (duration)
        //  - immersion.esi.default.max: для +20°C → 10 дней (duration)
        // До фикса (mapper выкидывал 0/0/0) builder падал на пустом root.default + children.
        // После — все точки с kind сохраняются как unknown/duration; домен принимает.
        $this->client->request('POST', "/cabinet/coating/coating/{$this->coatingId}/edit", [
            'title' => 'Updated Coating',
            'description' => 'Updated description.',
            'volumeSolid' => 50,
            'massDensity' => 1.5,
            'base' => 'EP',
            'minDft' => 80,
            'maxDft' => 150,
            'tdsDft' => 100,
            'applicationMinTemp' => 5,
            'pack' => 1.0,
            'dryToTouch' => [
                ['temperature_at' => 20, 'kind' => 'duration', 'days' => 0, 'hours' => 1, 'minutes' => 0],
            ],
            'fullCure' => [
                ['temperature_at' => 20, 'kind' => 'duration', 'days' => 1, 'hours' => 0, 'minutes' => 0],
            ],
            'manufacturer' => ['id' => $this->manufacturerId],
            'minRecoatingInterval' => [
                'default' => ['points' => [
                    ['temperature_at' => 35, 'kind' => 'duration', 'days' => 0, 'hours' => 2, 'minutes' => 0],
                ]],
                'branches' => [
                    'immersion' => [
                        'default' => ['points' => [
                            ['temperature_at' => 20, 'kind' => 'duration', 'days' => 0, 'hours' => 20, 'minutes' => 0],
                        ]],
                        'branches' => [
                            'esi' => [
                                'default' => ['points' => [
                                    ['temperature_at' => 20, 'kind' => 'duration', 'days' => 5, 'hours' => 2, 'minutes' => 0],
                                ]],
                                'branches' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'maxRecoatingInterval' => [
                'default' => ['points' => [
                    // unknown: производитель не указал верхнюю границу для общего случая
                    ['temperature_at' => 35, 'kind' => 'unknown', 'days' => 0, 'hours' => 0, 'minutes' => 0],
                ]],
                'branches' => [
                    'immersion' => [
                        'default' => ['points' => [
                            ['temperature_at' => 20, 'kind' => 'duration', 'days' => 12, 'hours' => 0, 'minutes' => 0],
                        ]],
                        'branches' => [
                            'esi' => [
                                'default' => ['points' => [
                                    ['temperature_at' => 20, 'kind' => 'duration', 'days' => 10, 'hours' => 0, 'minutes' => 0],
                                ]],
                                'branches' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertResponseRedirects(
            null,
            null,
            'Expected redirect after successful POST; got: ' . $this->client->getResponse()->getContent(),
        );

        // Reload и проверка состояния
        $container = $this->client->getContainer();
        $repoEm = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
        $repoEm->clear();

        /** @var \App\Coatings\Domain\Repository\CoatingRepositoryInterface $repo */
        $repo = $container->get(\App\Coatings\Domain\Repository\CoatingRepositoryInterface::class);
        $coating = $repo->findOneById($this->coatingId);
        $this->assertNotNull($coating);

        $maxTree = $coating->getMaxRecoatingInterval();
        $this->assertNotNull($maxTree, 'max-tree должен быть сохранён (не null), несмотря на root unknown');

        // root.default.points[0] должна быть unknown (time_in_minutes = null)
        $rootDefault = $maxTree->default;
        $this->assertCount(1, $rootDefault->points);
        $this->assertNull($rootDefault->points[0]->timeInMinutes, 'root.max@+35°C должно быть null (unknown)');

        // immersion.default.points[0] должна быть duration 12 дней = 17280 минут
        $immersionNode = $maxTree->findNode('immersion');
        $this->assertNotNull($immersionNode);
        $this->assertSame(17280, $immersionNode->default->points[0]->timeInMinutes);

        // immersion.esi.default.points[0] = 10 дней = 14400 минут
        $esiNode = $maxTree->findNode('immersion', 'ep');
        // ESI ключ может зависеть от case-normalize; пробуем оба варианта
        $esiNode = $maxTree->findNode('immersion', 'esi') ?? $maxTree->findNode('immersion', 'ESI');
        $this->assertNotNull($esiNode, 'esi branch должен существовать');
        $this->assertSame(14400, $esiNode->default->points[0]->timeInMinutes);
    }
```

- [ ] **Step 3: Запустить новый тест**

```bash
vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/Coating/UpdateActionRecoatingTreeTest.php --filter testSubmittingMaxTreeWithRootUnknownAndChildrenSetIsAccepted
```

Expected: PASS.

- [ ] **Step 4: Прогнать весь набор**

```bash
vendor/bin/phpunit
```

Expected: всё зелёное (кроме известных pre-existing `GetMeActionTest` / `GetUserActionTest`).

- [ ] **Step 5: Commit checkpoint**

Suggested message: `test(coating): functional coverage for kind-aware max-tree (root unknown + child durations)`.

---

## Done

После Task 8: пользователь различает «нет данных» и «без ограничения» на форме, compare и list-modal; конкретный сценарий «root.max unknown, immersion 12д, esi 10д» сохраняется без AppException. Domain-инвариант recoating-tree (default обязателен при наличии children) не тронут — отложен на отдельную задачу про nullable-default-on-node.
