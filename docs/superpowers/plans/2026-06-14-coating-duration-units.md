# Coating Duration Canonicalization — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Свести все длительности (drying, full cure, recoating interval) к одной канонической единице — `int $minutes` — в БД и в полях агрегата; на границах (DTO, форма, Twig) использовать `CarbonInterval`. Заодно перевести `recoatingInterval` в температурно-зависимую серию (как `dryToTouch`/`fullCure`), с линейной интерполяцией min и max через существующий каркас `Series`.

**Architecture:**
- В БД: int минуты везде. `dry_to_touch`/`full_cure` уже JSONB, нужно округлить значения с float → int. `min/max_recoating_interval` (FLOAT в часах) сливаются в новый JSONB `recoating_interval` — массив точек `{temperature_at, min_minutes, max_minutes}`.
- Boundary type: `CarbonInterval` в DTO, Twig (`|duration` фильтр), форме (макрос `duration_input` с тремя инпутами «дни / часы / мин»).
- `RecoatingIntervalSeries` — композитный VO: хранит публичный `list<RecoatingIntervalAtTemperature>`, под капотом — две `DryingTimeSeries` (для min и для max), чтобы переиспользовать готовую интерполяцию.
- Mapper — единственная точка конверсии форма ↔ CarbonInterval ↔ int минуты. Никакого голого `float $hours` или `int $minutes` снаружи Domain не появляется.

**Tech Stack:** PHP 8.3, Symfony, Carbon 3.x (уже в зависимостях), Doctrine ORM/DBAL, PostgreSQL 17 JSONB, PHPUnit 9.5, Bootstrap 5, Twig.

**Spec:** часть продолжающейся работы по `docs/superpowers/specs/2026-06-06-coating-vo-refactor-design.md` — закрывает «Out of scope» пункт про temperature-dependent recoating + единый канон единиц.

**Note: коммиты пользователь делает сам.** Шаги `git add / git commit` в плане **не выполняются**, после каждой задачи только запускаются тесты.

---

## File Structure

### Создаются

| Файл | Ответственность |
|---|---|
| `app/src/Shared/Infrastructure/Twig/Extension/DurationExtension.php` | Twig-фильтр `\|duration` → `CarbonInterval::minutes($v)->cascade()->forHumans()` |
| `app/src/Shared/Infrastructure/Templates/components/duration_input.html.twig` | Twig-макрос с тремя инпутами «дни / часы / мин» + label |
| `app/src/Coatings/Domain/Aggregate/Coating/RecoatingIntervalAtTemperature.php` | VO точки серии: `temperatureAt`, `minMinutes`, `?maxMinutes`; инвариант `min <= max` |
| `app/src/Coatings/Domain/Aggregate/Coating/RecoatingIntervalSeries.php` | Композитный VO; публ. `list<RecoatingIntervalAtTemperature>`; внутри 2 × `DryingTimeSeries` для interpolation; `getPoint(int $tempC): ?RecoatingIntervalAtTemperature` |
| `app/src/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalSeriesType.php` | DBAL Type: JSONB ↔ `RecoatingIntervalSeries` |
| `app/src/Coatings/Application/DTO/Coatings/RecoatingIntervalPointDTO.php` | DTO точки: `int temperature_at`, `CarbonInterval min`, `?CarbonInterval max` |
| `app/migrations/Version20260614HHMMSS.php` | (а) round drying/full_cure JSONB float → int; (б) new `recoating_interval` JSONB; backfill `* 60`, `temperature_at=20`; drop old columns |
| `app/tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalAtTemperatureTest.php` | Unit-тесты VO точки |
| `app/tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalSeriesTest.php` | Unit-тесты композитной серии + interpolation |
| `app/tests/Unit/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalSeriesTypeTest.php` | Unit-тесты DBAL |
| `app/tests/Unit/Shared/Infrastructure/Twig/Extension/DurationExtensionTest.php` | Unit-тесты фильтра |
| `app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingMapperTest.php` | Unit-тесты парсинга days/hours/minutes ↔ CarbonInterval и сборки RecoatingIntervalSeries |

### Модифицируются

| Файл | Что меняется |
|---|---|
| `app/src/Coatings/Domain/Aggregate/Coating/TimeAtTemperature.php` | `float $timeInMinutes` → `int`; добавить `getInterval(): CarbonInterval`; обновить `jsonSerialize` (int останется int) |
| `app/src/Coatings/Domain/Aggregate/Coating/DryingTimeSeries.php` | Поправить `createPoint()` — кастовать `(int) round($value)` для интерполяции |
| `app/src/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesType.php` | Парсить `(int)` вместо `(float)` |
| `app/src/Coatings/Application/DTO/Coatings/DryingTimePointDTO.php` | `float $time_in_minutes` → `int` |
| `app/src/Coatings/Application/DTO/Coatings/RecoatingIntervalDTO.php` | Полная переделка: становится `list<RecoatingIntervalPointDTO>` (или удаляется, а `CoatingDTO::$recoatingInterval` получает тип `list<RecoatingIntervalPointDTO>`) |
| `app/src/Coatings/Application/DTO/Coatings/CoatingDTO.php` | `recoatingInterval` — `list<RecoatingIntervalPointDTO>` |
| `app/src/Coatings/Domain/Aggregate/Coating/Coating.php` | Удалить `minRecoatingInterval`/`maxRecoatingInterval` (float); добавить `private RecoatingIntervalSeries $recoatingInterval`; заменить `setRecoatingIntervalBounds()` на `changeRecoatingInterval(RecoatingIntervalSeries)`; `getMinRecoatingInterval()/getMaxRecoatingInterval()` — удалить, добавить `getRecoatingInterval(): RecoatingIntervalSeries` |
| `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml` | Удалить `<field name="minRecoatingInterval" ...>` и `<field name="maxRecoatingInterval">`; добавить `<field name="recoatingInterval" column="recoating_interval" type="recoating_interval_series"/>` |
| `app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php` | (а) парсить `days/hours/minutes` → `CarbonInterval` в DTO; (б) собирать `RecoatingIntervalDTO` как массив точек; (в) `buildInputDataFromDto` раскладывает `int minutes` обратно на `days/hours/minutes`; (г) валидация под новый shape |
| `app/src/Coatings/Application/UseCase/Command/CreateCoating/CreateCoatingCommandHandler.php` | Строит `RecoatingIntervalSeries` из DTO-списка |
| `app/src/Coatings/Application/UseCase/Command/UpdateCoating/UpdateCoatingCommandHandler.php` | Заменить `setRecoatingIntervalBounds($min,$max)` на `changeRecoatingInterval(RecoatingIntervalSeries)` |
| `app/src/Coatings/Application/DTO/Coatings/CoatingDTOTransformer.php` | Сериализует `RecoatingIntervalSeries` в массив точек |
| `app/config/packages/doctrine.yaml` | Регистрация `recoating_interval_series` DBAL type |
| `app/src/Shared/Infrastructure/Templates/admin/coating/coating/form.html.twig` | (а) использовать макрос `duration_input` для строк dryToTouch/fullCure; (б) card «Интервал перекрытия» становится таблицей: «°C — Min (д/ч/мин) — Max (д/ч/мин)» с кнопкой «Добавить точку» |
| `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig` | В превью-модалке и карточке списка использовать `\|duration` |
| `app/src/Shared/Infrastructure/Templates/admin/coating/coating/compare.html.twig` (если уже есть после comparison-плана) | То же |

### Не трогаем

- Comparison-фичу (`ComparisonBasket`/`ComparisonDiffService`/etc.) — отдельный план.
- Extended-search фичу (`CoatingsFilter::manufacturerIds`) — не пересекается.

---

## Conventions

**Запуск тестов:**
```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/path/to/Test.php
```

**Миграции:**
```bash
docker-compose exec -T manager_php-cli php bin/console doctrine:migrations:migrate --no-interaction
```

**Cache:**
```bash
docker-compose exec -T manager_php-cli php bin/console cache:clear --env=dev
```

**psql:**
```bash
docker-compose exec manager_db psql -U "$DB_USER" -d "$DB_NAME" -c "<SQL>"
```

---

## Phase 1: Canonical int minutes for drying series

Цель: `TimeAtTemperature::$timeInMinutes` становится `int`, JSONB-данные округляются. `DryingTimeSeries` продолжает интерполировать корректно (округляем результат интерполяции до int).

### Task 1: TimeAtTemperature → int + getInterval()

**Files:**
- Modify: `app/src/Coatings/Domain/Aggregate/Coating/TimeAtTemperature.php`
- Modify: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/TimeAtTemperatureTest.php`

- [ ] **Step 1: Обновить тесты под int + CarbonInterval**

В `TimeAtTemperatureTest.php` заменить float-ассерты на int и добавить тесты на `getInterval()`:

```php
public function testTimeInMinutesIsInt(): void
{
    $point = new TimeAtTemperature(20, 10);
    $this->assertSame(10, $point->timeInMinutes);
}

public function testNegativeMinutesThrow(): void
{
    $this->expectException(AppException::class);
    new TimeAtTemperature(20, -1);
}

public function testGetIntervalReturnsCarbonInterval(): void
{
    $point = new TimeAtTemperature(20, 150);
    $interval = $point->getInterval();
    $this->assertInstanceOf(\Carbon\CarbonInterval::class, $interval);
    $this->assertSame(150.0, $interval->totalMinutes);
}

public function testJsonSerializeKeepsIntMinutes(): void
{
    $point = new TimeAtTemperature(20, 10);
    $this->assertSame(
        ['temperature_at' => 20, 'time_in_minutes' => 10, 'is_calculated' => false],
        $point->jsonSerialize(),
    );
}
```

(Существующие тесты с `10.5` минутами удалить либо заменить на `11`.)

- [ ] **Step 2: Запустить тесты — должны FAIL**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/TimeAtTemperatureTest.php
```

- [ ] **Step 3: Обновить `TimeAtTemperature.php`**

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Domain\Aggregate\ValueObject\SeriesPoint;
use App\Shared\Infrastructure\Exception\AppException;
use Carbon\CarbonInterval;

final readonly class TimeAtTemperature implements SeriesPoint
{
    public function __construct(
        public int $temperatureAt,
        public int $timeInMinutes,
        public bool $isCalculated = false,
    ) {
        if ($timeInMinutes < 0) {
            throw new AppException('Время не может быть отрицательным.');
        }
    }

    public function getKey(): int { return $this->temperatureAt; }
    public function getValue(): int { return $this->timeInMinutes; }
    public function isCalculated(): bool { return $this->isCalculated; }

    public function getInterval(): CarbonInterval
    {
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

- [ ] **Step 4: Поправить `DryingTimeSeries::createPoint()` — округлять float от интерполяции до int**

`app/src/Coatings/Domain/Aggregate/Coating/DryingTimeSeries.php`, метод `createPoint`:

```php
protected function createPoint(int|float $key, int|float $value, bool $isCalculated): SeriesPoint
{
    return new TimeAtTemperature((int) $key, (int) round($value), $isCalculated);
}
```

- [ ] **Step 5: Запустить тесты — должны пройти**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/
```

Ожидаемый: OK для TimeAtTemperature и DryingTimeSeries (последний может потребовать поправить assert'ы для интерполированных значений — округлённое int вместо float).

---

### Task 2: DryingTimePointDTO → int + DryingTimeSeriesType → int

**Files:**
- Modify: `app/src/Coatings/Application/DTO/Coatings/DryingTimePointDTO.php`
- Modify: `app/src/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesType.php`

- [ ] **Step 1: DryingTimePointDTO**

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

class DryingTimePointDTO
{
    public int $temperature_at;
    public int $time_in_minutes;
    public bool $is_calculated = false;
}
```

- [ ] **Step 2: DryingTimeSeriesType.convertToPHPValue**

В методе `convertToPHPValue` кастовать `(int)`:

```php
$points = array_map(
    fn(array $p) => new TimeAtTemperature(
        (int) $p['temperature_at'],
        (int) $p['time_in_minutes'],
        (bool) ($p['is_calculated'] ?? false),
    ),
    $raw,
);
```

- [ ] **Step 3: Прогнать тесты DBAL Type**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesTypeTest.php
```

Поправить тесты, если в фикстурах есть `10.0` минут → `10`.

---

### Task 3: Миграция — округлить float → int в JSONB

**Files:**
- Create: `app/migrations/Version20260614HHMMSS.php` (сгенерируется автоматически — назовём его «migrations A»)

- [ ] **Step 1: Сгенерировать миграцию**

```bash
docker-compose exec -T manager_php-cli php bin/console doctrine:migrations:generate
```

- [ ] **Step 2: Заполнить `up()` / `down()`**

```php
public function getDescription(): string
{
    return 'Round drying time JSONB float values to int (canonical unit = minutes).';
}

public function up(Schema $schema): void
{
    $this->addSql(<<<SQL
        UPDATE coatings_coating
        SET dry_to_touch = (
            SELECT jsonb_agg(
                jsonb_set(
                    elem,
                    '{time_in_minutes}',
                    to_jsonb(round((elem->>'time_in_minutes')::numeric)::int)
                )
            )
            FROM jsonb_array_elements(dry_to_touch) elem
        )
        WHERE dry_to_touch IS NOT NULL
    SQL);

    $this->addSql(<<<SQL
        UPDATE coatings_coating
        SET full_cure = (
            SELECT jsonb_agg(
                jsonb_set(
                    elem,
                    '{time_in_minutes}',
                    to_jsonb(round((elem->>'time_in_minutes')::numeric)::int)
                )
            )
            FROM jsonb_array_elements(full_cure) elem
        )
        WHERE full_cure IS NOT NULL
    SQL);
}

public function down(Schema $schema): void
{
    // No-op: округление до int необратимо без потерь, но дробных частей не было нигде, кроме редких случаев.
}
```

- [ ] **Step 3: Применить миграцию + проверить**

```bash
docker-compose exec -T manager_php-cli php bin/console doctrine:migrations:migrate --no-interaction
docker-compose exec manager_db psql -U "$DB_USER" -d "$DB_NAME" -c "SELECT dry_to_touch FROM coatings_coating LIMIT 3"
```

Ожидаемый: значения `time_in_minutes` в JSON — целые числа без `.0`.

---

## Phase 2: CarbonInterval at output (Twig filter)

Цель: пользователь видит «2 часа 30 мин», а не «150».

### Task 4: DurationExtension

**Files:**
- Create: `app/src/Shared/Infrastructure/Twig/Extension/DurationExtension.php`
- Create: `app/tests/Unit/Shared/Infrastructure/Twig/Extension/DurationExtensionTest.php`

- [ ] **Step 1: Тесты**

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Twig\Extension;

use App\Shared\Infrastructure\Twig\Extension\DurationExtension;
use Carbon\CarbonInterval;
use PHPUnit\Framework\TestCase;

class DurationExtensionTest extends TestCase
{
    private DurationExtension $ext;

    protected function setUp(): void
    {
        $this->ext = new DurationExtension();
        CarbonInterval::setLocale('ru');
    }

    public function testZeroMinutes(): void
    {
        $this->assertSame('—', $this->ext->formatMinutes(null));
    }

    public function testSmallNumberMinutes(): void
    {
        // 12 минут
        $this->assertStringContainsString('12', $this->ext->formatMinutes(12));
        $this->assertStringContainsString('мин', $this->ext->formatMinutes(12));
    }

    public function testCascadeIntoHours(): void
    {
        // 150 минут = 2 часа 30 минут
        $out = $this->ext->formatMinutes(150);
        $this->assertStringContainsString('2', $out);
        $this->assertStringContainsString('ч', $out);
    }

    public function testCascadeIntoDays(): void
    {
        // 14400 = 10 суток
        $out = $this->ext->formatMinutes(14400);
        $this->assertStringContainsString('10', $out);
    }

    public function testAcceptsCarbonInterval(): void
    {
        $ci = CarbonInterval::hours(2)->minutes(30);
        $out = $this->ext->format($ci);
        $this->assertStringContainsString('2', $out);
    }
}
```

- [ ] **Step 2: Запустить — должно FAIL**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/Shared/Infrastructure/Twig/Extension/DurationExtensionTest.php
```

- [ ] **Step 3: Реализация**

```php
<?php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Twig\Extension;

use Carbon\CarbonInterval;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class DurationExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('duration_minutes', [$this, 'formatMinutes']),
            new TwigFilter('duration', [$this, 'format']),
        ];
    }

    /** Принимает CarbonInterval (для DTO/getInterval) */
    public function format(?CarbonInterval $interval): string
    {
        if ($interval === null) {
            return '—';
        }
        return $interval->copy()->locale('ru')->cascade()->forHumans(['parts' => 2]);
    }

    /** Принимает int минут (для прямого вывода из числа) */
    public function formatMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        return $this->format(CarbonInterval::minutes($minutes));
    }
}
```

Symfony автоматически зарегистрирует extension через autoconfigure.

- [ ] **Step 4: Тесты — должны пройти**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/Shared/Infrastructure/Twig/Extension/DurationExtensionTest.php
```

- [ ] **Step 5: Cache clear + проверить, что Twig видит фильтры**

```bash
docker-compose exec -T manager_php-cli php bin/console cache:clear --env=dev
docker-compose exec -T manager_php-cli php bin/console debug:twig --filter=duration
```

Ожидаемый: видны фильтры `duration` и `duration_minutes`.

---

### Task 5: Заменить выводы float-минут в шаблонах на `|duration_minutes`

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig`
- Modify (если нужно): другие шаблоны где упоминается `time_in_minutes`, `minRecoatingInterval`, `maxRecoatingInterval`

- [ ] **Step 1: Найти все вхождения**

```bash
grep -rn "time_in_minutes\|RecoatingInterval\|minRecoat\|maxRecoat" app/src/Shared/Infrastructure/Templates/
```

- [ ] **Step 2: Заменить вывод**

Было:
```twig
{{ coating.dryToTouch[0].time_in_minutes }} мин
```

Стало:
```twig
{{ coating.dryToTouch[0].time_in_minutes|duration_minutes }}
```

Для recoating (если ещё показывается float в часах — пока оставить как есть, поменяем в Phase 4):
```twig
{# временно после Phase 1, до Phase 4: #}
{{ coating.minRecoatingInterval }} ч
```

- [ ] **Step 3: Cache clear + ручная проверка**

```bash
docker-compose exec -T manager_php-cli php bin/console cache:clear --env=dev
```

Открыть `/cabinet/coating/coating/list`, выбрать любое покрытие — проверить, что время сушки рендерится как «12 мин» / «2 ч 30 мин» / «10 сут».

---

## Phase 3: CarbonInterval at input (Twig macro + Mapper)

Цель: пользователь вводит длительность как «дни / часы / мин», Mapper собирает в `CarbonInterval`, при сохранении агрегат хранит `int minutes`.

### Task 6: Twig-макрос `duration_input`

**Files:**
- Create: `app/src/Shared/Infrastructure/Templates/components/duration_input.html.twig`

- [ ] **Step 1: Реализация**

```twig
{# Использование:
       {% from '/components/duration_input.html.twig' import duration_input %}
       {{ duration_input('dryToTouch[0]', dryToTouch[0]) }}
   value — это либо null, либо ассоциативный массив с ключами days/hours/minutes (готовится в CoatingMapper::buildInputDataFromDto).
#}
{% macro duration_input(name, value, required=false) %}
    <div class="input-group input-group-sm" style="max-width: 280px;">
        <input type="number"
               name="{{ name }}[days]"
               value="{{ value.days ?? 0 }}"
               class="form-control"
               min="0"
               max="365"
               step="1"
               {% if required %}required{% endif %}>
        <span class="input-group-text">д</span>
        <input type="number"
               name="{{ name }}[hours]"
               value="{{ value.hours ?? 0 }}"
               class="form-control"
               min="0"
               max="23"
               step="1"
               {% if required %}required{% endif %}>
        <span class="input-group-text">ч</span>
        <input type="number"
               name="{{ name }}[minutes]"
               value="{{ value.minutes ?? 0 }}"
               class="form-control"
               min="0"
               max="59"
               step="1"
               {% if required %}required{% endif %}>
        <span class="input-group-text">мин</span>
    </div>
{% endmacro %}
```

- [ ] **Step 2: Cache clear**

```bash
docker-compose exec -T manager_php-cli php bin/console cache:clear --env=dev
```

---

### Task 7: CoatingMapper — парсинг dni/часы/мин ↔ int minutes

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php`
- Create: `app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingMapperTest.php`

- [ ] **Step 1: Тест — Mapper парсит dni/часы/мин и складывает в total minutes**

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Mapper;

use App\Coatings\Infrastructure\Mapper\CoatingMapper;
use Carbon\CarbonInterval;
use PHPUnit\Framework\TestCase;

class CoatingMapperTest extends TestCase
{
    private CoatingMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new CoatingMapper();
    }

    public function testParseDurationFromInputAddsUpDaysHoursMinutes(): void
    {
        // 1 день + 2 часа + 30 минут = 24*60 + 120 + 30 = 1590 мин
        $totalMinutes = $this->mapper->parseDurationInput(['days' => 1, 'hours' => 2, 'minutes' => 30]);
        $this->assertSame(1590, $totalMinutes);
    }

    public function testParseDurationFromInputAcceptsEmptyAsZero(): void
    {
        $this->assertSame(0, $this->mapper->parseDurationInput([]));
        $this->assertSame(0, $this->mapper->parseDurationInput(['days' => '', 'hours' => '', 'minutes' => '']));
    }

    public function testDecomposeMinutesIntoDaysHoursMinutes(): void
    {
        $this->assertSame(
            ['days' => 1, 'hours' => 2, 'minutes' => 30],
            $this->mapper->decomposeDurationForForm(1590),
        );
        $this->assertSame(
            ['days' => 0, 'hours' => 0, 'minutes' => 12],
            $this->mapper->decomposeDurationForForm(12),
        );
        $this->assertSame(
            ['days' => 10, 'hours' => 0, 'minutes' => 0],
            $this->mapper->decomposeDurationForForm(14400),
        );
    }
}
```

- [ ] **Step 2: Тест запустить — fails**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Mapper/CoatingMapperTest.php
```

- [ ] **Step 3: Добавить методы парсинга/декомпозиции в `CoatingMapper.php`**

```php
public function parseDurationInput(array $raw): int
{
    $days    = (int) ($raw['days']    ?? 0);
    $hours   = (int) ($raw['hours']   ?? 0);
    $minutes = (int) ($raw['minutes'] ?? 0);
    return $days * 24 * 60 + $hours * 60 + $minutes;
}

/** @return array{days: int, hours: int, minutes: int} */
public function decomposeDurationForForm(int $totalMinutes): array
{
    $days = intdiv($totalMinutes, 24 * 60);
    $rem = $totalMinutes - $days * 24 * 60;
    $hours = intdiv($rem, 60);
    $minutes = $rem - $hours * 60;
    return ['days' => $days, 'hours' => $hours, 'minutes' => $minutes];
}
```

- [ ] **Step 4: В `buildPointsFromInput` использовать парсер**

```php
private function buildPointsFromInput(array $rawPoints): array
{
    return array_values(array_map(function (array $raw): DryingTimePointDTO {
        $point = new DryingTimePointDTO();
        $point->temperature_at = (int) ($raw['temperature_at'] ?? 20);
        // Поддерживаем оба формата: новый {days, hours, minutes} и старый {time_in_minutes}.
        if (isset($raw['time_in_minutes'])) {
            $point->time_in_minutes = (int) $raw['time_in_minutes'];
        } else {
            $point->time_in_minutes = $this->parseDurationInput($raw);
        }
        $point->is_calculated = (bool) ($raw['is_calculated'] ?? false);
        return $point;
    }, $rawPoints));
}
```

- [ ] **Step 5: В `buildInputDataFromDto` разложить минуты на dni/часы/мин для каждой точки**

В методе `buildInputDataFromDto` — для `dryToTouch` и `fullCure` пройти и добавить компонентные ключи:

```php
$vars['dryToTouch'] = array_map(
    fn(DryingTimePointDTO $p) => array_merge(
        $this->decomposeDurationForForm($p->time_in_minutes),
        [
            'temperature_at' => $p->temperature_at,
            'time_in_minutes' => $p->time_in_minutes, // оставляем для обратной совместимости с другими местами
            'is_calculated' => $p->is_calculated,
        ],
    ),
    $coatingDTO->dryToTouch,
);
// то же самое для fullCure
```

- [ ] **Step 6: Прогнать тесты**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Mapper/CoatingMapperTest.php
```

Ожидаемый: OK.

---

### Task 8: form.html.twig — переключить dryToTouch/fullCure на макрос

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/form.html.twig`

- [ ] **Step 1: Найти блок dryToTouch**

```bash
grep -n "dryToTouch\|fullCure" app/src/Shared/Infrastructure/Templates/admin/coating/coating/form.html.twig
```

- [ ] **Step 2: Заменить инпуты на макрос**

В начало шаблона добавить:

```twig
{% from '/components/duration_input.html.twig' import duration_input %}
```

В строках dryToTouch (и аналогично fullCure):

Было:
```twig
<input type="number" name="dryToTouch[0][time_in_minutes]" value="{{ dryToTouch[0].time_in_minutes ?? 0 }}" min="0" step="0.01" class="form-control">
```

Стало:
```twig
{{ duration_input('dryToTouch[0]', dryToTouch[0] ?? {}, required=true) }}
<input type="hidden" name="dryToTouch[0][temperature_at]" value="{{ dryToTouch[0].temperature_at ?? 20 }}">
```

(Температура для одной точки фиксирована — `+20°C`. Когда появятся доп. строки — `temperature_at` станет редактируемым числом рядом.)

- [ ] **Step 3: Обновить валидацию в `CoatingMapper::getValidationCollectionCoating()`**

Для `dryToTouch` и `fullCure` поменять Collection: разрешить и старый ключ `time_in_minutes`, и новые `days/hours/minutes`:

```php
'dryToTouch' => [
    new Assert\NotBlank(),
    new Assert\All([
        new Assert\Collection([
            'temperature_at' => [new Assert\NotBlank(), new Assert\Type('numeric')],
            'days'    => new Assert\Optional([new Assert\Type('numeric')]),
            'hours'   => new Assert\Optional([new Assert\Type('numeric')]),
            'minutes' => new Assert\Optional([new Assert\Type('numeric')]),
            'time_in_minutes' => new Assert\Optional([new Assert\Type('numeric')]),
            'is_calculated' => new Assert\Optional(new Assert\Type('numeric')),
        ], allowExtraFields: true),
    ]),
],
```

- [ ] **Step 4: Cache clear + ручная проверка формы**

```bash
docker-compose exec -T manager_php-cli php bin/console cache:clear --env=dev
```

Открыть `/cabinet/coating/coating/list`, отредактировать любое покрытие:
- В блоке «Сухой на отлип» видны три инпута: 0 д | 0 ч | 12 мин (для записи `12`).
- Ввод «1 день 2 часа 30 мин» сохраняется как `1590` в JSONB.
- При повторном открытии форма показывает «1 д 2 ч 30 мин».

---

## Phase 4: RecoatingInterval as temperature-dependent series

### Task 9: RecoatingIntervalAtTemperature VO

**Files:**
- Create: `app/src/Coatings/Domain/Aggregate/Coating/RecoatingIntervalAtTemperature.php`
- Create: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalAtTemperatureTest.php`

- [ ] **Step 1: Тесты**

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalAtTemperature;
use App\Shared\Infrastructure\Exception\AppException;
use Carbon\CarbonInterval;
use PHPUnit\Framework\TestCase;

class RecoatingIntervalAtTemperatureTest extends TestCase
{
    public function testValidConstruction(): void
    {
        $p = new RecoatingIntervalAtTemperature(20, 720, 4320);
        $this->assertSame(20, $p->temperatureAt);
        $this->assertSame(720, $p->minMinutes);
        $this->assertSame(4320, $p->maxMinutes);
    }

    public function testNullMaxAllowed(): void
    {
        $p = new RecoatingIntervalAtTemperature(20, 720, null);
        $this->assertNull($p->maxMinutes);
    }

    public function testNegativeMinThrows(): void
    {
        $this->expectException(AppException::class);
        new RecoatingIntervalAtTemperature(20, -1, null);
    }

    public function testMaxLessThanMinThrows(): void
    {
        $this->expectException(AppException::class);
        new RecoatingIntervalAtTemperature(20, 720, 60);
    }

    public function testEqualMinMaxAllowed(): void
    {
        $p = new RecoatingIntervalAtTemperature(20, 720, 720);
        $this->assertSame(720, $p->maxMinutes);
    }

    public function testGetMinInterval(): void
    {
        $p = new RecoatingIntervalAtTemperature(20, 720, null);
        $this->assertSame(720.0, $p->getMinInterval()->totalMinutes);
    }

    public function testGetMaxIntervalNull(): void
    {
        $p = new RecoatingIntervalAtTemperature(20, 720, null);
        $this->assertNull($p->getMaxInterval());
    }

    public function testGetMaxIntervalCarbon(): void
    {
        $p = new RecoatingIntervalAtTemperature(20, 720, 4320);
        $this->assertSame(4320.0, $p->getMaxInterval()->totalMinutes);
    }

    public function testJsonSerialize(): void
    {
        $p = new RecoatingIntervalAtTemperature(20, 720, 4320);
        $this->assertSame(
            ['temperature_at' => 20, 'min_minutes' => 720, 'max_minutes' => 4320],
            $p->jsonSerialize(),
        );
    }

    public function testJsonSerializeNullMax(): void
    {
        $p = new RecoatingIntervalAtTemperature(20, 720, null);
        $this->assertSame(
            ['temperature_at' => 20, 'min_minutes' => 720, 'max_minutes' => null],
            $p->jsonSerialize(),
        );
    }
}
```

- [ ] **Step 2: Запустить — FAIL**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalAtTemperatureTest.php
```

- [ ] **Step 3: Реализация**

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Infrastructure\Exception\AppException;
use Carbon\CarbonInterval;
use JsonSerializable;

final readonly class RecoatingIntervalAtTemperature implements JsonSerializable
{
    public function __construct(
        public int $temperatureAt,
        public int $minMinutes,
        public ?int $maxMinutes,
    ) {
        if ($minMinutes < 0) {
            throw new AppException('Минимальный интервал не может быть отрицательным.');
        }
        if ($maxMinutes !== null) {
            if ($maxMinutes < 0) {
                throw new AppException('Максимальный интервал не может быть отрицательным.');
            }
            if ($maxMinutes < $minMinutes) {
                throw new AppException(sprintf(
                    'Минимальный интервал (%d мин) при +%d°C не может превышать максимальный (%d мин).',
                    $minMinutes, $temperatureAt, $maxMinutes,
                ));
            }
        }
    }

    public function getMinInterval(): CarbonInterval
    {
        return CarbonInterval::minutes($this->minMinutes);
    }

    public function getMaxInterval(): ?CarbonInterval
    {
        return $this->maxMinutes === null ? null : CarbonInterval::minutes($this->maxMinutes);
    }

    public function jsonSerialize(): array
    {
        return [
            'temperature_at' => $this->temperatureAt,
            'min_minutes' => $this->minMinutes,
            'max_minutes' => $this->maxMinutes,
        ];
    }
}
```

- [ ] **Step 4: Тесты — PASS**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalAtTemperatureTest.php
```

---

### Task 10: RecoatingIntervalSeries composite VO

**Files:**
- Create: `app/src/Coatings/Domain/Aggregate/Coating/RecoatingIntervalSeries.php`
- Create: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalSeriesTest.php`

- [ ] **Step 1: Тесты**

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalAtTemperature;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalSeries;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

class RecoatingIntervalSeriesTest extends TestCase
{
    public function testEmptySeriesThrows(): void
    {
        $this->expectException(AppException::class);
        new RecoatingIntervalSeries([]);
    }

    public function testSinglePointAllowed(): void
    {
        $series = new RecoatingIntervalSeries([
            new RecoatingIntervalAtTemperature(20, 720, null),
        ]);
        $this->assertCount(1, $series->points);
    }

    public function testSortedByTemperature(): void
    {
        $series = new RecoatingIntervalSeries([
            new RecoatingIntervalAtTemperature(30, 60, 120),
            new RecoatingIntervalAtTemperature(5, 1440, 4320),
            new RecoatingIntervalAtTemperature(20, 240, 720),
        ]);
        $this->assertSame(5, $series->points[0]->temperatureAt);
        $this->assertSame(20, $series->points[1]->temperatureAt);
        $this->assertSame(30, $series->points[2]->temperatureAt);
    }

    public function testDuplicateTemperatureThrows(): void
    {
        $this->expectException(AppException::class);
        new RecoatingIntervalSeries([
            new RecoatingIntervalAtTemperature(20, 240, null),
            new RecoatingIntervalAtTemperature(20, 240, null),
        ]);
    }

    public function testGetPointExact(): void
    {
        $series = new RecoatingIntervalSeries([
            new RecoatingIntervalAtTemperature(5, 1440, 4320),
            new RecoatingIntervalAtTemperature(20, 240, 720),
        ]);
        $p = $series->getPoint(20);
        $this->assertNotNull($p);
        $this->assertSame(240, $p->minMinutes);
        $this->assertSame(720, $p->maxMinutes);
    }

    public function testGetPointInterpolatesBothMinAndMax(): void
    {
        // min при +5°C = 1440, при +20°C = 240. при +12°C ≈ 1440 + (240-1440)*(12-5)/(20-5) = 1440 - 560 = 880
        // max при +5°C = 4320, при +20°C = 720.  при +12°C ≈ 4320 + (720-4320)*7/15 = 4320 - 1680 = 2640
        $series = new RecoatingIntervalSeries([
            new RecoatingIntervalAtTemperature(5, 1440, 4320),
            new RecoatingIntervalAtTemperature(20, 240, 720),
        ]);
        $p = $series->getPoint(12);
        $this->assertNotNull($p);
        $this->assertEqualsWithDelta(880, $p->minMinutes, 1);
        $this->assertEqualsWithDelta(2640, $p->maxMinutes, 1);
    }

    public function testGetPointOutOfRangeReturnsNull(): void
    {
        $series = new RecoatingIntervalSeries([
            new RecoatingIntervalAtTemperature(20, 240, 720),
            new RecoatingIntervalAtTemperature(30, 60, 120),
        ]);
        $this->assertNull($series->getPoint(5));
        $this->assertNull($series->getPoint(40));
    }

    public function testMixedMaxNullAndNumericThrows(): void
    {
        // Если в одной точке max задан, а в другой null — это рассогласованный профиль.
        $this->expectException(AppException::class);
        new RecoatingIntervalSeries([
            new RecoatingIntervalAtTemperature(20, 240, 720),
            new RecoatingIntervalAtTemperature(30, 60, null),
        ]);
    }

    public function testAllNullMaxAllowed(): void
    {
        $series = new RecoatingIntervalSeries([
            new RecoatingIntervalAtTemperature(20, 240, null),
            new RecoatingIntervalAtTemperature(30, 60, null),
        ]);
        $p = $series->getPoint(25);
        $this->assertNotNull($p);
        $this->assertNull($p->maxMinutes);
    }

    public function testJsonSerialize(): void
    {
        $series = new RecoatingIntervalSeries([
            new RecoatingIntervalAtTemperature(20, 240, 720),
        ]);
        $this->assertSame(
            [['temperature_at' => 20, 'min_minutes' => 240, 'max_minutes' => 720]],
            $series->jsonSerialize(),
        );
    }
}
```

- [ ] **Step 2: Запустить — FAIL**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalSeriesTest.php
```

- [ ] **Step 3: Реализация**

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Infrastructure\Exception\AppException;
use JsonSerializable;

final readonly class RecoatingIntervalSeries implements JsonSerializable
{
    /** @var list<RecoatingIntervalAtTemperature> отсортированный по temperatureAt, ключи уникальны */
    public array $points;

    private DryingTimeSeries $minSeries;
    private ?DryingTimeSeries $maxSeries; // null если все maxMinutes == null

    public function __construct(array $points)
    {
        if (count($points) === 0) {
            throw new AppException('RecoatingIntervalSeries не может быть пустой.');
        }
        foreach ($points as $i => $p) {
            if (!$p instanceof RecoatingIntervalAtTemperature) {
                throw new AppException("Элемент {$i} не RecoatingIntervalAtTemperature.");
            }
        }
        usort($points, fn($a, $b) => $a->temperatureAt <=> $b->temperatureAt);
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            if ($points[$i]->temperatureAt === $points[$i - 1]->temperatureAt) {
                throw new AppException(sprintf('Дублирующаяся температура %d°C.', $points[$i]->temperatureAt));
            }
        }

        // Все maxMinutes либо все null, либо все заданы.
        $nullsInMax = array_filter($points, fn($p) => $p->maxMinutes === null);
        $hasMax = count($nullsInMax) === 0;
        if (!$hasMax && count($nullsInMax) !== count($points)) {
            throw new AppException(
                'Профиль recoatingInterval несогласован: max задан в одних точках и null в других.',
            );
        }

        $this->points = array_values($points);

        // Под капотом: две DryingTimeSeries-подобные серии для интерполяции.
        $this->minSeries = new DryingTimeSeries(array_map(
            fn(RecoatingIntervalAtTemperature $p) => new TimeAtTemperature($p->temperatureAt, $p->minMinutes),
            $this->points,
        ));
        $this->maxSeries = $hasMax
            ? new DryingTimeSeries(array_map(
                fn(RecoatingIntervalAtTemperature $p) => new TimeAtTemperature($p->temperatureAt, $p->maxMinutes),
                $this->points,
            ))
            : null;
    }

    public function getPoint(int $temperatureAt): ?RecoatingIntervalAtTemperature
    {
        $minPoint = $this->minSeries->getPoint($temperatureAt);
        if ($minPoint === null) {
            return null;
        }
        $maxPoint = $this->maxSeries?->getPoint($temperatureAt);
        return new RecoatingIntervalAtTemperature(
            $temperatureAt,
            $minPoint->getValue(),
            $maxPoint?->getValue(),
        );
    }

    public function jsonSerialize(): array
    {
        return array_map(fn(RecoatingIntervalAtTemperature $p) => $p->jsonSerialize(), $this->points);
    }
}
```

**Примечание:** `DryingTimeSeries` проверяет монотонность («чем выше температура, тем меньше время»). Для recoating это физически верно: чем теплее, тем быстрее можно перекрывать. Если в реальных данных найдутся «обратные» профили — тогда нужно создать `MonotonicallyDecreasingDurationSeries` без монотонности, либо разрешить нестрогую монотонность отдельным флагом. Сейчас исходим из того, что монотонность есть.

- [ ] **Step 4: Тесты — PASS**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalSeriesTest.php
```

---

### Task 11: RecoatingIntervalSeriesType DBAL

**Files:**
- Create: `app/src/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalSeriesType.php`
- Create: `app/tests/Unit/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalSeriesTypeTest.php`
- Modify: `app/config/packages/doctrine.yaml`

- [ ] **Step 1: Тест на (де)сериализацию**

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalAtTemperature;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalSeries;
use App\Coatings\Infrastructure\Database\DBAL\RecoatingIntervalSeriesType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\TestCase;

class RecoatingIntervalSeriesTypeTest extends TestCase
{
    private RecoatingIntervalSeriesType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        if (!Type::hasType(RecoatingIntervalSeriesType::NAME)) {
            Type::addType(RecoatingIntervalSeriesType::NAME, RecoatingIntervalSeriesType::class);
        }
        $this->type = Type::getType(RecoatingIntervalSeriesType::NAME);
        $this->platform = new PostgreSQLPlatform();
    }

    public function testToDatabase(): void
    {
        $series = new RecoatingIntervalSeries([
            new RecoatingIntervalAtTemperature(20, 720, 4320),
        ]);
        $json = $this->type->convertToDatabaseValue($series, $this->platform);
        $this->assertSame(
            '[{"temperature_at":20,"min_minutes":720,"max_minutes":4320}]',
            $json,
        );
    }

    public function testFromDatabase(): void
    {
        $json = '[{"temperature_at":20,"min_minutes":720,"max_minutes":null}]';
        $series = $this->type->convertToPHPValue($json, $this->platform);
        $this->assertInstanceOf(RecoatingIntervalSeries::class, $series);
        $this->assertCount(1, $series->points);
        $this->assertSame(20, $series->points[0]->temperatureAt);
        $this->assertSame(720, $series->points[0]->minMinutes);
        $this->assertNull($series->points[0]->maxMinutes);
    }

    public function testNullRoundtrip(): void
    {
        $this->assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        $this->assertNull($this->type->convertToPHPValue(null, $this->platform));
    }
}
```

- [ ] **Step 2: Запустить — FAIL**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalSeriesTypeTest.php
```

- [ ] **Step 3: Реализация**

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalAtTemperature;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalSeries;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

final class RecoatingIntervalSeriesType extends JsonType
{
    public const NAME = 'recoating_interval_series';

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof RecoatingIntervalSeries) {
            throw new \InvalidArgumentException(
                'Expected RecoatingIntervalSeries, got ' . (is_object($value) ? $value::class : gettype($value))
            );
        }
        return parent::convertToDatabaseValue($value->jsonSerialize(), $platform);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?RecoatingIntervalSeries
    {
        if ($value === null) {
            return null;
        }
        $raw = parent::convertToPHPValue($value, $platform);
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Expected JSON array for RecoatingIntervalSeries.');
        }
        $points = array_map(
            fn(array $p) => new RecoatingIntervalAtTemperature(
                (int) $p['temperature_at'],
                (int) $p['min_minutes'],
                isset($p['max_minutes']) ? (int) $p['max_minutes'] : null,
            ),
            $raw,
        );
        return new RecoatingIntervalSeries($points);
    }
}
```

- [ ] **Step 4: Регистрация в `doctrine.yaml`**

```yaml
doctrine:
    dbal:
        types:
            drying_time_series: App\Coatings\Infrastructure\Database\DBAL\DryingTimeSeriesType
            recoating_interval_series: App\Coatings\Infrastructure\Database\DBAL\RecoatingIntervalSeriesType
```

- [ ] **Step 5: Тесты — PASS + sanity check**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalSeriesTypeTest.php
docker-compose exec -T manager_php-cli php bin/console cache:clear --env=dev
```

---

### Task 12: Coating aggregate — заменить min/max recoating на RecoatingIntervalSeries

**Files:**
- Modify: `app/src/Coatings/Domain/Aggregate/Coating/Coating.php`
- Modify: `app/src/Coatings/Domain/Factory/CoatingFactory.php`

- [ ] **Step 1: Удалить поля и сеттер**

Удалить из `Coating.php`:
- свойства `$minRecoatingInterval`, `$maxRecoatingInterval`;
- методы `getMinRecoatingInterval()`, `getMaxRecoatingInterval()`, `setRecoatingIntervalBounds()`.

- [ ] **Step 2: Добавить поле, конструкторный параметр, геттер, setter**

```php
private RecoatingIntervalSeries $recoatingInterval;

// в конструктор — заменить параметры min/max:
RecoatingIntervalSeries $recoatingInterval,
// и в теле:
$this->setRecoatingInterval($recoatingInterval);

public function getRecoatingInterval(): RecoatingIntervalSeries
{
    return $this->recoatingInterval;
}

public function setRecoatingInterval(RecoatingIntervalSeries $recoatingInterval): void
{
    $this->recoatingInterval = $recoatingInterval;
}
```

Импорт: `use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalSeries;`

- [ ] **Step 3: Обновить `CoatingFactory.php` под новый параметр**

```php
public function create(
    Uuid $id,
    string $title,
    string $description,
    int $volumeSolid,
    float $massDensity,
    CoatingBase $base,
    DftRange $dftRange,
    int $applicationMinTemp,
    DryingTimeSeries $dryToTouch,
    RecoatingIntervalSeries $recoatingInterval,
    DryingTimeSeries $fullCure,
    Manufacturer $manufacturer,
    float $pack,
    ?string $thinner,
): Coating {
    return new Coating(
        $id,
        $title,
        $description,
        $volumeSolid,
        $massDensity,
        $base,
        $dftRange,
        $applicationMinTemp,
        $dryToTouch,
        $recoatingInterval,
        $fullCure,
        $pack,
        $thinner,
        $manufacturer,
        $this->coatingSpecification,
    );
}
```

- [ ] **Step 4: Прогнать существующие unit-тесты — найти все падающие callsites**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit
```

Ожидаемо: падения в местах, где есть `setRecoatingIntervalBounds` или прямой `new Coating(...)`. Поправить — собирать `RecoatingIntervalSeries` с одной точкой `+20°C` по умолчанию.

---

### Task 13: ORM XML

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml`

- [ ] **Step 1: Удалить две старые field-строки и добавить одну новую**

Удалить:
```xml
<field name="minRecoatingInterval" column="min_recoating_interval" type="float"/>
<field name="maxRecoatingInterval" column="max_recoating_interval" type="float" nullable="true"/>
```

Добавить:
```xml
<field name="recoatingInterval" column="recoating_interval" type="recoating_interval_series" nullable="false"/>
```

- [ ] **Step 2: Schema validate**

```bash
docker-compose exec -T manager_php-cli php bin/console doctrine:schema:validate
```

Допустимы преэкзистные FAIL (Manufacturer/CoatingTag legacy), главное — чтобы Coating-маппинг был валиден.

---

### Task 14: Миграция — recoating_interval JSONB + drop old columns

**Files:**
- Create: `app/migrations/Version20260614HHMMSS.php` (вторая миграция этой даты)

- [ ] **Step 1: Сгенерировать**

```bash
docker-compose exec -T manager_php-cli php bin/console doctrine:migrations:generate
```

- [ ] **Step 2: Заполнить**

```php
public function getDescription(): string
{
    return 'Convert min/max recoating interval (hours, separate columns) into recoating_interval JSONB array of points (minutes).';
}

public function up(Schema $schema): void
{
    $this->addSql('ALTER TABLE coatings_coating ADD COLUMN recoating_interval JSONB');

    $this->addSql(<<<SQL
        UPDATE coatings_coating
        SET recoating_interval = jsonb_build_array(
            jsonb_build_object(
                'temperature_at', 20,
                'min_minutes',    (min_recoating_interval * 60)::int,
                'max_minutes',    CASE WHEN max_recoating_interval IS NULL THEN NULL ELSE (max_recoating_interval * 60)::int END
            )
        )
    SQL);

    $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN recoating_interval SET NOT NULL');
    $this->addSql('ALTER TABLE coatings_coating DROP COLUMN min_recoating_interval');
    $this->addSql('ALTER TABLE coatings_coating DROP COLUMN max_recoating_interval');
}

public function down(Schema $schema): void
{
    $this->addSql('ALTER TABLE coatings_coating ADD COLUMN min_recoating_interval DOUBLE PRECISION');
    $this->addSql('ALTER TABLE coatings_coating ADD COLUMN max_recoating_interval DOUBLE PRECISION');

    $this->addSql(<<<SQL
        UPDATE coatings_coating
        SET
            min_recoating_interval = ((recoating_interval->0->>'min_minutes')::numeric / 60),
            max_recoating_interval = CASE
                WHEN recoating_interval->0->>'max_minutes' IS NULL THEN NULL
                ELSE ((recoating_interval->0->>'max_minutes')::numeric / 60)
            END
    SQL);

    $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN min_recoating_interval SET NOT NULL');
    $this->addSql('ALTER TABLE coatings_coating DROP COLUMN recoating_interval');
}
```

- [ ] **Step 3: Применить + проверить**

```bash
docker-compose exec -T manager_php-cli php bin/console doctrine:migrations:migrate --no-interaction
docker-compose exec manager_db psql -U "$DB_USER" -d "$DB_NAME" -c "SELECT id, recoating_interval FROM coatings_coating LIMIT 3"
```

Ожидаемый формат:
```
[{"temperature_at": 20, "min_minutes": 720, "max_minutes": null}]
```

Для записи `min=12 час, max=null` → `min_minutes=720, max_minutes=null`. Проверить.

---

### Task 15: DTO — RecoatingIntervalPointDTO + изменения в CoatingDTO/RecoatingIntervalDTO

**Files:**
- Create: `app/src/Coatings/Application/DTO/Coatings/RecoatingIntervalPointDTO.php`
- Modify: `app/src/Coatings/Application/DTO/Coatings/RecoatingIntervalDTO.php` — превратить в обёртку списка ИЛИ удалить
- Modify: `app/src/Coatings/Application/DTO/Coatings/CoatingDTO.php`

- [ ] **Step 1: Новый DTO точки**

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

use Carbon\CarbonInterval;

class RecoatingIntervalPointDTO
{
    public int $temperature_at;
    public CarbonInterval $min;
    public ?CarbonInterval $max = null;
}
```

- [ ] **Step 2: Заменить старый `RecoatingIntervalDTO`**

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

/**
 * Список точек профиля интервала перекрытия.
 * Сохранён как класс-обёртка (а не голый array<RecoatingIntervalPointDTO>),
 * чтобы DTO остался расширяемым.
 */
class RecoatingIntervalDTO
{
    /** @var list<RecoatingIntervalPointDTO> */
    public array $points = [];
}
```

- [ ] **Step 3: `CoatingDTO::$recoatingInterval` остаётся типом `RecoatingIntervalDTO`** (если оно уже там).

Если в `CoatingDTO` указан тип `?RecoatingIntervalDTO` или `RecoatingIntervalDTO` — менять не надо. Если был float — поправить:

```bash
grep -n "recoatingInterval" app/src/Coatings/Application/DTO/Coatings/CoatingDTO.php
```

Должно быть `public RecoatingIntervalDTO $recoatingInterval;`.

---

### Task 16: CoatingMapper — recoatingInterval парсинг и декомпозиция

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php`

- [ ] **Step 1: Тест — Mapper собирает RecoatingIntervalDTO как список точек**

В `CoatingMapperTest.php` добавить:

```php
public function testBuildRecoatingIntervalDtoFromInput(): void
{
    $input = [
        'recoatingInterval' => [
            ['temperature_at' => 20, 'min' => ['days' => 0, 'hours' => 12, 'minutes' => 0], 'max' => ['days' => 3, 'hours' => 0, 'minutes' => 0]],
            ['temperature_at' => 5,  'min' => ['days' => 1, 'hours' => 0, 'minutes' => 0], 'max' => ['days' => '', 'hours' => '', 'minutes' => '']],
        ],
        'maxRecoatingUnlimited' => ['1' => '1'], // чекбокс «без верхней границы» для второй строки (index=1)
    ];
    $dto = $this->mapper->buildRecoatingIntervalDto($input);
    $this->assertCount(2, $dto->points);
    $this->assertSame(20, $dto->points[0]->temperature_at);
    $this->assertSame(720.0, $dto->points[0]->min->totalMinutes);
    $this->assertSame(4320.0, $dto->points[0]->max->totalMinutes);
    $this->assertSame(5, $dto->points[1]->temperature_at);
    $this->assertSame(1440.0, $dto->points[1]->min->totalMinutes);
    $this->assertNull($dto->points[1]->max);
}

public function testDecomposeRecoatingIntervalForForm(): void
{
    $series = new RecoatingIntervalSeries([
        new RecoatingIntervalAtTemperature(20, 720, 4320),
    ]);
    $raw = $this->mapper->decomposeRecoatingIntervalForForm($series);
    $this->assertCount(1, $raw);
    $this->assertSame(20, $raw[0]['temperature_at']);
    $this->assertSame(['days' => 0, 'hours' => 12, 'minutes' => 0], $raw[0]['min']);
    $this->assertSame(['days' => 3, 'hours' => 0, 'minutes' => 0], $raw[0]['max']);
}
```

- [ ] **Step 2: Реализовать методы в Mapper**

```php
public function buildRecoatingIntervalDto(array $inputData): RecoatingIntervalDTO
{
    $dto = new RecoatingIntervalDTO();
    $rows = $inputData['recoatingInterval'] ?? [];
    $unlimited = $inputData['maxRecoatingUnlimited'] ?? [];

    foreach ($rows as $idx => $row) {
        $point = new RecoatingIntervalPointDTO();
        $point->temperature_at = (int) ($row['temperature_at'] ?? 20);

        $minTotal = $this->parseDurationInput($row['min'] ?? []);
        $point->min = CarbonInterval::minutes($minTotal);

        $isUnlimited = isset($unlimited[(string) $idx]) || isset($unlimited[$idx]);
        if ($isUnlimited) {
            $point->max = null;
        } else {
            $maxTotal = $this->parseDurationInput($row['max'] ?? []);
            $point->max = $maxTotal > 0 ? CarbonInterval::minutes($maxTotal) : null;
        }

        $dto->points[] = $point;
    }

    return $dto;
}

/**
 * @return list<array{
 *     temperature_at: int,
 *     min: array{days:int, hours:int, minutes:int},
 *     max: array{days:int, hours:int, minutes:int}|null,
 * }>
 */
public function decomposeRecoatingIntervalForForm(RecoatingIntervalSeries $series): array
{
    return array_map(fn(RecoatingIntervalAtTemperature $p) => [
        'temperature_at' => $p->temperatureAt,
        'min' => $this->decomposeDurationForForm($p->minMinutes),
        'max' => $p->maxMinutes !== null ? $this->decomposeDurationForForm($p->maxMinutes) : null,
    ], $series->points);
}
```

- [ ] **Step 3: Подключить в `buildCoatingDtoFromInputData`**

Заменить старый блок с `recoatingInterval[min]/[max]` (см. строки 77–83):

```php
$dto->recoatingInterval = $this->buildRecoatingIntervalDto($inputData);
```

- [ ] **Step 4: Подключить в `buildInputDataFromDto`**

```php
if (isset($vars['recoatingInterval']) && $vars['recoatingInterval'] instanceof RecoatingIntervalDTO) {
    // CoatingDTOTransformer уже отдаёт нам DTO; для формы — нам нужно разложить точки.
    // Но decomposeRecoatingIntervalForForm принимает RecoatingIntervalSeries (domain),
    // а сюда приходит DTO. Поправляем: разложение делаем на уровне DTO.
    $vars['recoatingInterval'] = array_map(fn(RecoatingIntervalPointDTO $p) => [
        'temperature_at' => $p->temperature_at,
        'min' => $this->decomposeDurationForForm((int) round($p->min->totalMinutes)),
        'max' => $p->max !== null ? $this->decomposeDurationForForm((int) round($p->max->totalMinutes)) : null,
    ], $vars['recoatingInterval']->points);
}
```

- [ ] **Step 5: Тесты — PASS**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Mapper/CoatingMapperTest.php
```

---

### Task 17: Create + Update handlers

**Files:**
- Modify: `app/src/Coatings/Application/UseCase/Command/CreateCoating/CreateCoatingCommandHandler.php`
- Modify: `app/src/Coatings/Application/UseCase/Command/UpdateCoating/UpdateCoatingCommandHandler.php`

- [ ] **Step 1: CreateCoatingCommandHandler — собрать `RecoatingIntervalSeries` из DTO**

В месте, где создаётся `Coating`/вызывается `CoatingFactory::create()`:

```php
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalAtTemperature;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalSeries;

$recoatingPoints = array_map(
    fn(RecoatingIntervalPointDTO $p) => new RecoatingIntervalAtTemperature(
        $p->temperature_at,
        (int) round($p->min->totalMinutes),
        $p->max !== null ? (int) round($p->max->totalMinutes) : null,
    ),
    $dto->recoatingInterval->points,
);
$recoatingInterval = new RecoatingIntervalSeries($recoatingPoints);

// затем — передать $recoatingInterval в factory вместо $minRecoatingInterval / $maxRecoatingInterval.
```

- [ ] **Step 2: UpdateCoatingCommandHandler — заменить вызов `setRecoatingIntervalBounds`**

Найти строку `$coating->setRecoatingIntervalBounds(...)` и заменить:

```php
if ($dto->recoatingInterval && $dto->recoatingInterval->points !== []) {
    $points = array_map(
        fn(RecoatingIntervalPointDTO $p) => new RecoatingIntervalAtTemperature(
            $p->temperature_at,
            (int) round($p->min->totalMinutes),
            $p->max !== null ? (int) round($p->max->totalMinutes) : null,
        ),
        $dto->recoatingInterval->points,
    );
    $coating->setRecoatingInterval(new RecoatingIntervalSeries($points));
}
```

- [ ] **Step 3: Прогнать unit-тесты**

```bash
docker-compose exec -T manager_php-cli vendor/bin/phpunit tests/Unit
```

---

### Task 18: CoatingDTOTransformer + form.html.twig + Validation

**Files:**
- Modify: `app/src/Coatings/Application/DTO/Coatings/CoatingDTOTransformer.php`
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/form.html.twig`
- Modify: `app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php` (метод `getValidationCollectionCoating`)

- [ ] **Step 1: CoatingDTOTransformer — Coating → DTO**

Найти блок, который собирал `RecoatingIntervalDTO` со скалярными min/max:

```bash
grep -n "RecoatingIntervalDTO\|recoatingInterval" app/src/Coatings/Application/DTO/Coatings/CoatingDTOTransformer.php
```

Заменить на:

```php
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalDTO;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalPointDTO;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalAtTemperature;

$riDto = new RecoatingIntervalDTO();
$riDto->points = array_map(function (RecoatingIntervalAtTemperature $p) {
    $pointDto = new RecoatingIntervalPointDTO();
    $pointDto->temperature_at = $p->temperatureAt;
    $pointDto->min = $p->getMinInterval();
    $pointDto->max = $p->getMaxInterval();
    return $pointDto;
}, $coating->getRecoatingInterval()->points);

$dto->recoatingInterval = $riDto;
```

- [ ] **Step 2: form.html.twig — переделать card «Интервал перекрытия» в таблицу**

Заменить текущий card с двумя инпутами в часах на таблицу (показываю шаблон; реальная разметка может отличаться оформлением):

```twig
{% from '/components/duration_input.html.twig' import duration_input %}

<div class="card mb-3">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span>Интервал перекрытия</span>
        <button type="button" class="btn btn-sm btn-outline-secondary"
                onclick="addRecoatingRow()">Добавить точку</button>
    </div>
    <div class="card-body">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th style="width: 100px;">°C</th>
                    <th>Min</th>
                    <th>Max</th>
                    <th style="width: 160px;">Без верхней<br>границы</th>
                </tr>
            </thead>
            <tbody id="recoatingIntervalRows">
                {% for i, row in inputData.recoatingInterval ?? [{temperature_at: 20, min: {days: 0, hours: 0, minutes: 0}, max: null}] %}
                    <tr>
                        <td>
                            <input type="number"
                                   name="recoatingInterval[{{ i }}][temperature_at]"
                                   value="{{ row.temperature_at ?? 20 }}"
                                   class="form-control form-control-sm"
                                   min="-50" max="100" required>
                        </td>
                        <td>{{ duration_input('recoatingInterval[' ~ i ~ '][min]', row.min ?? {}, required=true) }}</td>
                        <td>{{ duration_input('recoatingInterval[' ~ i ~ '][max]', row.max ?? {}) }}</td>
                        <td class="text-center">
                            <input type="checkbox"
                                   name="maxRecoatingUnlimited[{{ i }}]"
                                   value="1"
                                   class="form-check-input"
                                   {% if row.max is null %}checked{% endif %}>
                        </td>
                    </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
</div>

<script>
function addRecoatingRow() {
    const tbody = document.getElementById('recoatingIntervalRows');
    const i = tbody.children.length;
    const row = document.createElement('tr');
    row.innerHTML = `
        <td><input type="number" name="recoatingInterval[${i}][temperature_at]" value="20" class="form-control form-control-sm" required></td>
        <td><div class="input-group input-group-sm">
            <input type="number" name="recoatingInterval[${i}][min][days]" value="0" class="form-control" min="0">
            <span class="input-group-text">д</span>
            <input type="number" name="recoatingInterval[${i}][min][hours]" value="0" class="form-control" min="0" max="23">
            <span class="input-group-text">ч</span>
            <input type="number" name="recoatingInterval[${i}][min][minutes]" value="0" class="form-control" min="0" max="59">
            <span class="input-group-text">мин</span>
        </div></td>
        <td><div class="input-group input-group-sm">
            <input type="number" name="recoatingInterval[${i}][max][days]" value="0" class="form-control" min="0">
            <span class="input-group-text">д</span>
            <input type="number" name="recoatingInterval[${i}][max][hours]" value="0" class="form-control" min="0" max="23">
            <span class="input-group-text">ч</span>
            <input type="number" name="recoatingInterval[${i}][max][minutes]" value="0" class="form-control" min="0" max="59">
            <span class="input-group-text">мин</span>
        </div></td>
        <td class="text-center"><input type="checkbox" name="maxRecoatingUnlimited[${i}]" value="1" class="form-check-input" checked></td>
    `;
    tbody.appendChild(row);
}
</script>
```

(JS — минималистичный, без библиотек. Если в проекте есть Stimulus — переделать на контроллер.)

- [ ] **Step 3: Обновить валидацию**

В `CoatingMapper::getValidationCollectionCoating()` заменить старый `recoatingInterval` Collection-блок (строки ~212–220) на:

```php
'recoatingInterval' => [
    new Assert\NotBlank(),
    new Assert\All([
        new Assert\Collection([
            'temperature_at' => [new Assert\NotBlank(), new Assert\Type('numeric')],
            'min' => [
                new Assert\NotBlank(),
                new Assert\Collection([
                    'days'    => new Assert\Optional([new Assert\Type('numeric')]),
                    'hours'   => new Assert\Optional([new Assert\Type('numeric')]),
                    'minutes' => new Assert\Optional([new Assert\Type('numeric')]),
                ], allowExtraFields: true),
            ],
            'max' => new Assert\Optional([
                new Assert\Collection([
                    'days'    => new Assert\Optional([new Assert\Type('numeric')]),
                    'hours'   => new Assert\Optional([new Assert\Type('numeric')]),
                    'minutes' => new Assert\Optional([new Assert\Type('numeric')]),
                ], allowExtraFields: true),
            ]),
        ], allowExtraFields: true),
    ]),
],
'maxRecoatingUnlimited' => new Assert\Optional([
    new Assert\Type('array'),
]),
```

- [ ] **Step 4: Cache clear**

```bash
docker-compose exec -T manager_php-cli php bin/console cache:clear --env=dev
```

---

### Task 19: View templates — Twig фильтры для recoating

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig` (превью-модалка)
- Modify: остальные шаблоны, где встречается старый `minRecoatingInterval`/`maxRecoatingInterval` (если такие есть)

- [ ] **Step 1: Найти**

```bash
grep -rn "minRecoatingInterval\|maxRecoatingInterval\|RecoatingInterval" app/src/Shared/Infrastructure/Templates/
```

- [ ] **Step 2: Заменить вывод на новый series**

Для каждой точки серии (или для первой точки, если в шаблоне одна строка):

Было:
```twig
<dt>Мин. интервал перекрытия</dt>
<dd>{{ coating.minRecoatingInterval }} ч</dd>
```

Стало (если ограничиваемся первой точкой при +20°C):
```twig
<dt>Интервал перекрытия при +{{ coating.recoatingInterval.points[0].temperature_at }}°C</dt>
<dd>
    {{ coating.recoatingInterval.points[0].min_minutes|duration_minutes }}
    {% if coating.recoatingInterval.points[0].max_minutes is null %}
        — без верхней границы
    {% else %}
        — {{ coating.recoatingInterval.points[0].max_minutes|duration_minutes }}
    {% endif %}
</dd>
```

Если хочется показать все точки — `{% for p in coating.recoatingInterval.points %}` блок.

- [ ] **Step 3: Cache clear + ручная проверка**

```bash
docker-compose exec -T manager_php-cli php bin/console cache:clear --env=dev
```

Открыть `/cabinet/coating/coating/list` — карточка покрытия `ПРОМЕТЕЙ РС 750` должна показывать «Интервал перекрытия при +20°C: 12 ч — без верхней границы».

---

## Phase 5: Smoke test

### Task 20: Browser smoke

**Files:** ничего — только ручная проверка.

- [ ] **Step 1: Список покрытий**

`http://localhost:6878/cabinet/coating/coating/list` — открывается без 500-х. Логи php-fpm — без ошибок гидрации.

- [ ] **Step 2: Превью покрытия**

В карточке `ПРОМЕТЕЙ РС 750`:
- Сушка на отлип: `12 мин`.
- Полное отверждение: `7 мин`.
- Интервал перекрытия при +20°C: `12 ч — без верхней границы`.

- [ ] **Step 3: Редактирование**

Открыть форму редактирования:
- В блоке «Сухой на отлип» три инпута: 0 д, 0 ч, 12 мин.
- В таблице «Интервал перекрытия» одна строка: 20 °C | 0 д 12 ч 0 мин | 0 д 0 ч 0 мин | ✅ без верхней границы.

Действия:
1. Изменить min на «0 д 6 ч 0 мин», сохранить.
2. Открыть psql: `SELECT recoating_interval FROM coatings_coating WHERE title = 'ПРОМЕТЕЙ РС 750'` — должно быть `[{"temperature_at": 20, "min_minutes": 360, "max_minutes": null}]`.
3. Снова открыть форму — должно показывать «0 д 6 ч 0 мин» и чекбокс «без верхней границы» отмечен.

- [ ] **Step 4: Добавление второй точки**

В форме нажать «Добавить точку», заполнить «5 °C, min 1 д 0 ч 0 мин, max 7 д 0 ч 0 мин» (снять чекбокс), сохранить. Открыть psql — массив из двух точек, отсортированных по температуре. Открыть форму — обе строки.

- [ ] **Step 5: Невалидные данные**

В форме поставить max < min — увидеть AppException-сообщение от Domain. (Текст — «Минимальный интервал … при +X°C не может превышать максимальный».)

---

## Self-Review

**Spec coverage:**
- Канон int минут в БД — Phase 1 (Tasks 1–3)
- CarbonInterval на выходе — Phase 2 (Tasks 4–5)
- CarbonInterval на входе через макрос — Phase 3 (Tasks 6–8)
- RecoatingInterval как temperature-dependent series — Phase 4 (Tasks 9–19)
- Линейная интерполяция через каркас Series — Task 10 (внутри RecoatingIntervalSeries через две DryingTimeSeries)
- Smoke — Task 20

**Type consistency:**
- `int $timeInMinutes` в `TimeAtTemperature` и `int $time_in_minutes` в `DryingTimePointDTO` — согласовано.
- `int $minMinutes`/`?int $maxMinutes` в `RecoatingIntervalAtTemperature`; `CarbonInterval $min`/`?CarbonInterval $max` в `RecoatingIntervalPointDTO` — согласовано через Mapper и Handler.
- `RecoatingIntervalSeries::getPoint(int $temperatureAt): ?RecoatingIntervalAtTemperature` — то же имя точки, одинаковая семантика.
- `setRecoatingInterval(RecoatingIntervalSeries)` в `Coating` — везде вызывается с одним типом.

**Placeholders:** просканировано — нет «TBD/TODO/implement later». Каждый шаг содержит конкретный код или конкретную команду.

**Open question:**
- DryingTimeSeries требует монотонности «выше temp → меньше время». Для recoatingInterval это обычно так же, но если найдётся обратный профиль — придётся снять монотонность (создать `DurationSeries` без неё и переключить `RecoatingIntervalSeries` на него). Решается на этапе Task 10 при появлении реальных данных.
- Inline JS в `form.html.twig` — простая реализация «Добавить точку». Если в проекте используется Stimulus/AlpineJS — переделать на контроллер при ревью.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-06-14-coating-duration-units.md`.

Two execution options:

1. **Subagent-Driven (recommended)** — fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session with checkpoints between tasks.

Which approach?
