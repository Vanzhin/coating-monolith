# Coating: контекстно-зависимый recoating interval — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Заменить плоские поля `*RecoatingInterval` в `Coating` на иммутабельное рекурсивное дерево `RecoatingIntervalTree`, чтобы хранить контекстно-зависимые интервалы перекрытия (среда × тип покрывающего слоя) с fallback'ом на дефолт ближайшего родителя.

**Architecture:** Composite-VO с обязательным `default` и опциональными `children`. Сериализуется в JSONB через Doctrine DBAL Type. Логика поиска с fallback живёт приватным helper'ом в `Coating` и обёрнута в типизированные shortcut-методы (`min/maxRecoatingFor`, `*PointAt`). Сейчас дерево заполняется единственным глобальным default; overrides придут на Этапе 2 (отдельный спек на UI).

**Tech Stack:** PHP 8.3, Symfony, Doctrine ORM 3.x (XML mapping), PHPUnit, PostgreSQL JSONB, Doctrine Migrations.

**Ссылка на спек:** `docs/superpowers/specs/2026-06-16-coating-recoating-interval-tree-design.md`

**Замечания для исполнителя:**
- Пользователь сам управляет git'ом — **не делать `git add` / `git commit`**. После каждой задачи: остановиться, рассказать что сделано, попросить пользователя посмотреть и закоммитить, дождаться перед началом следующей.
- Все тесты — PHPUnit, запускаются из директории `app/` командой `vendor/bin/phpunit <путь>` или через `bin/phpunit` если есть. Если в проекте есть Makefile/composer-script — узнать у пользователя.
- В тестах для доменных ошибок используется `App\Shared\Infrastructure\Exception\AppException`, не `InvalidArgumentException`. Однако структурные ошибки (битый тип ключа в массиве) — это `\InvalidArgumentException` (как в спеке). Соответствует существующему стилю.
- Никаких лишних правок — только то, что в плане.

---

## Файловая структура

**Новые файлы:**
- `app/src/Coatings/Domain/Aggregate/Coating/EnvironmentType.php` — enum
- `app/src/Coatings/Domain/Aggregate/Coating/RecoatingIntervalTree.php` — composite VO
- `app/src/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalTreeType.php` — DBAL Type
- `app/src/Shared/Infrastructure/Database/Migrations/Version<YYYYMMDDHHMMSS>.php` — SQL миграция данных
- `app/tests/Unit/Coatings/Domain/Aggregate/Coating/EnvironmentTypeTest.php`
- `app/tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalTreeTest.php`
- `app/tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php` — новый файл, тесты shortcut-методов
- `app/tests/Unit/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalTreeTypeTest.php`

**Изменяемые файлы:**
- `app/src/Coatings/Domain/Aggregate/Coating/DryingTimeSeries.php` — добавить `fromArray()`
- `app/src/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesType.php` — использовать `fromArray()`
- `app/tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php` — тест `fromArray()`
- `app/src/Coatings/Domain/Aggregate/Coating/Coating.php` — типы полей, setters, shortcut-методы, helper
- `app/src/Coatings/Domain/Service/CoatingMaker.php` — приём `RecoatingIntervalTree`
- `app/src/Coatings/Application/UseCase/Command/UpdateCoating/UpdateCoatingCommandHandler.php` — оборачивание Series в Tree
- `app/src/Coatings/Application/UseCase/Command/CreateCoating/CreateCoatingCommandHandler.php` — оборачивание Series в Tree
- `app/src/Coatings/Application/DTO/Coatings/CoatingDTOTransformer.php` — чтение `default` из Tree
- `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml` — смена типа колонок
- `app/config/packages/doctrine.yaml` — регистрация типа

---

## Task 1: `DryingTimeSeries::fromArray()` — извлечь парсинг из DBAL Type

**Цель:** Сделать обратную сборку `DryingTimeSeries` из массива первого класса хелпером — он понадобится и в `RecoatingIntervalTree::fromArray()`, и в существующем `DryingTimeSeriesType`. Текущая логика парсинга `TimeAtTemperature` живёт прямо в `DryingTimeSeriesType::convertToPHPValue()` — переносим её в фабрику `DryingTimeSeries::fromArray()`.

**Файлы:**
- Modify: `app/src/Coatings/Domain/Aggregate/Coating/DryingTimeSeries.php`
- Modify: `app/src/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesType.php`
- Modify: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php`

- [ ] **Step 1: Написать падающий тест `fromArray()` round-trip**

Добавить в `DryingTimeSeriesTest.php` в конец класса:

```php
public function testFromArrayRoundTripsSerialization(): void
{
    $original = new DryingTimeSeries(
        new TimeAtTemperature(20, 10),
        new TimeAtTemperature(30, 5),
    );

    $raw = json_decode(json_encode($original), true);
    $restored = DryingTimeSeries::fromArray($raw);

    $this->assertSame(
        json_decode(json_encode($original), true),
        json_decode(json_encode($restored), true),
    );
}

public function testFromArrayPreservesIsCalculatedFlag(): void
{
    $raw = [
        ['temperature_at' => 20, 'time_in_minutes' => 10, 'is_calculated' => false],
        ['temperature_at' => 25, 'time_in_minutes' => 8,  'is_calculated' => true],
        ['temperature_at' => 30, 'time_in_minutes' => 5,  'is_calculated' => false],
    ];

    $series = DryingTimeSeries::fromArray($raw);

    $this->assertCount(3, $series->points);
    $this->assertSame(20, $series->points[0]->temperatureAt);
    $this->assertFalse($series->points[0]->isCalculated);
    $this->assertTrue($series->points[1]->isCalculated);
}
```

- [ ] **Step 2: Запустить тесты — увидеть FAIL**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php`
Ожидание: оба новых теста падают с "Method DryingTimeSeries::fromArray() does not exist" (или Error: undefined method).

- [ ] **Step 3: Добавить `fromArray()` в `DryingTimeSeries`**

В `DryingTimeSeries.php` добавить публичный статический метод сразу после `jsonSerialize()`:

```php
    /**
     * Обратная сборка из плоского массива точек (формат jsonSerialize()).
     *
     * @param list<array<string, mixed>> $rows
     * @throws AppException
     */
    public static function fromArray(array $rows): self
    {
        $points = array_map(
            fn(array $row): TimeAtTemperature => new TimeAtTemperature(
                (int) $row['temperature_at'],
                (int) $row['time_in_minutes'],
                (bool) ($row['is_calculated'] ?? false),
            ),
            $rows,
        );

        return new self(...$points);
    }
```

- [ ] **Step 4: Запустить тесты — оба должны проходить**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php`
Ожидание: PASS — все тесты файла, включая 2 новых.

- [ ] **Step 5: Переключить `DryingTimeSeriesType` на `fromArray()`**

В `DryingTimeSeriesType.php` заменить тело `convertToPHPValue()` так, чтобы парсинг шёл через фабрику:

```php
    public function convertToPHPValue($value, AbstractPlatform $platform): ?DryingTimeSeries
    {
        if ($value === null) {
            return null;
        }
        $rows = parent::convertToPHPValue($value, $platform);
        if (!is_array($rows)) {
            throw new \UnexpectedValueException('Для DryingTimeSeries ожидается JSON-массив.');
        }
        return DryingTimeSeries::fromArray($rows);
    }
```

Импорт `TimeAtTemperature` теперь не нужен — удалить из `use`-блока.

- [ ] **Step 6: Запустить весь набор тестов Coating'а — ничего не должно сломаться**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/Coatings/`
Ожидание: все тесты PASS.

- [ ] **Step 7: Остановиться — попросить пользователя закоммитить**

Сообщить: «Task 1 готов: вынес `DryingTimeSeries::fromArray()`, DBAL Type использует её. Тесты зелёные. Прошу закоммитить и продолжить.»
Дождаться ответа.

---

## Task 2: `EnvironmentType` enum

**Цель:** Завести enum, который будет ключом верхнего уровня дерева.

**Файлы:**
- Create: `app/src/Coatings/Domain/Aggregate/Coating/EnvironmentType.php`
- Create: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/EnvironmentTypeTest.php`

- [ ] **Step 1: Написать падающий тест cases**

Создать `app/tests/Unit/Coatings/Domain/Aggregate/Coating/EnvironmentTypeTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use PHPUnit\Framework\TestCase;

final class EnvironmentTypeTest extends TestCase
{
    public function testHasThreeCases(): void
    {
        $values = array_map(static fn(EnvironmentType $c) => $c->value, EnvironmentType::cases());
        sort($values);
        $this->assertSame(['atmospheric', 'immersion', 'special'], $values);
    }

    public function testFromValue(): void
    {
        $this->assertSame(EnvironmentType::Atmospheric, EnvironmentType::from('atmospheric'));
        $this->assertSame(EnvironmentType::Immersion,   EnvironmentType::from('immersion'));
        $this->assertSame(EnvironmentType::Special,     EnvironmentType::from('special'));
    }
}
```

- [ ] **Step 2: Запустить — увидеть FAIL**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/EnvironmentTypeTest.php`
Ожидание: FAIL — «EnvironmentType not found».

- [ ] **Step 3: Создать enum**

Создать `app/src/Coatings/Domain/Aggregate/Coating/EnvironmentType.php`:

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

enum EnvironmentType: string
{
    case Atmospheric = 'atmospheric';
    case Immersion   = 'immersion';
    case Special     = 'special';
}
```

- [ ] **Step 4: Запустить — оба теста PASS**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/EnvironmentTypeTest.php`
Ожидание: PASS.

- [ ] **Step 5: Остановиться — попросить пользователя закоммитить**

Сообщить: «Task 2 готов: enum `EnvironmentType` (Atmospheric/Immersion/Special). Прошу закоммитить.»
Дождаться ответа.

---

## Task 3: `RecoatingIntervalTree` VO

**Цель:** Композитный иммутабельный узел дерева. Обязательный `default`, опциональные строковые `children`, рекурсивная (де)сериализация. Никакой логики поиска — она будет в `Coating`.

**Файлы:**
- Create: `app/src/Coatings/Domain/Aggregate/Coating/RecoatingIntervalTree.php`
- Create: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalTreeTest.php`

- [ ] **Step 1: Написать падающий тест конструктора и базовых инвариантов**

Создать `app/tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalTreeTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use PHPUnit\Framework\TestCase;

final class RecoatingIntervalTreeTest extends TestCase
{
    public function testLeafStoresDefault(): void
    {
        $series = new DryingTimeSeries(new TimeAtTemperature(20, 10));

        $tree = new RecoatingIntervalTree($series);

        $this->assertSame($series, $tree->default);
        $this->assertSame([], $tree->children);
    }

    public function testNestedTreeStoresChildren(): void
    {
        $epSeries = new DryingTimeSeries(new TimeAtTemperature(20, 30));
        $envDefault = new DryingTimeSeries(new TimeAtTemperature(20, 7));
        $globalDefault = new DryingTimeSeries(new TimeAtTemperature(20, 14));

        $envBranch = new RecoatingIntervalTree(
            $envDefault,
            ['EP' => new RecoatingIntervalTree($epSeries)],
        );
        $root = new RecoatingIntervalTree(
            $globalDefault,
            ['atmospheric' => $envBranch],
        );

        $this->assertArrayHasKey('atmospheric', $root->children);
        $this->assertArrayHasKey('EP', $root->children['atmospheric']->children);
        $this->assertSame($epSeries, $root->children['atmospheric']->children['EP']->default);
    }

    public function testRejectsNonStringChildKey(): void
    {
        $series = new DryingTimeSeries(new TimeAtTemperature(20, 10));

        $this->expectException(\InvalidArgumentException::class);
        new RecoatingIntervalTree($series, [42 => new RecoatingIntervalTree($series)]);
    }

    public function testRejectsEmptyStringChildKey(): void
    {
        $series = new DryingTimeSeries(new TimeAtTemperature(20, 10));

        $this->expectException(\InvalidArgumentException::class);
        new RecoatingIntervalTree($series, ['' => new RecoatingIntervalTree($series)]);
    }

    public function testRejectsNonTreeChildValue(): void
    {
        $series = new DryingTimeSeries(new TimeAtTemperature(20, 10));

        $this->expectException(\InvalidArgumentException::class);
        // @phpstan-ignore-next-line
        new RecoatingIntervalTree($series, ['atmospheric' => 'not a tree']);
    }
}
```

- [ ] **Step 2: Запустить — FAIL**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalTreeTest.php`
Ожидание: FAIL — class not found.

- [ ] **Step 3: Создать VO**

Создать `app/src/Coatings/Domain/Aggregate/Coating/RecoatingIntervalTree.php`:

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

final class RecoatingIntervalTree implements \JsonSerializable
{
    /** @var array<string, RecoatingIntervalTree> */
    public readonly array $children;

    /**
     * @param array<string, RecoatingIntervalTree> $children
     */
    public function __construct(
        public readonly DryingTimeSeries $default,
        array $children = [],
    ) {
        foreach ($children as $key => $child) {
            if (!is_string($key) || $key === '' || !$child instanceof self) {
                throw new \InvalidArgumentException('RecoatingIntervalTree: bad child entry');
            }
        }
        $this->children = $children;
    }

    public function jsonSerialize(): array
    {
        return [
            'default'  => $this->default,
            'children' => array_map(static fn(self $c) => $c->jsonSerialize(), $this->children),
        ];
    }

    /**
     * @param array{default: array, children?: array<string, array>} $raw
     */
    public static function fromArray(array $raw): self
    {
        $children = [];
        foreach ($raw['children'] ?? [] as $key => $childRaw) {
            $children[(string) $key] = self::fromArray($childRaw);
        }
        return new self(DryingTimeSeries::fromArray($raw['default']), $children);
    }
}
```

- [ ] **Step 4: Запустить — все 5 тестов PASS**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalTreeTest.php`
Ожидание: PASS.

- [ ] **Step 5: Написать падающий тест сериализации**

Добавить в `RecoatingIntervalTreeTest.php`:

```php
public function testJsonSerializeProducesNestedStructure(): void
{
    $epSeries = new DryingTimeSeries(new TimeAtTemperature(20, 30));
    $envDefault = new DryingTimeSeries(new TimeAtTemperature(20, 7));
    $globalDefault = new DryingTimeSeries(new TimeAtTemperature(20, 14));

    $tree = new RecoatingIntervalTree(
        $globalDefault,
        [
            'atmospheric' => new RecoatingIntervalTree(
                $envDefault,
                ['EP' => new RecoatingIntervalTree($epSeries)],
            ),
        ],
    );

    $expected = [
        'default'  => [['temperature_at' => 20, 'time_in_minutes' => 14, 'is_calculated' => false]],
        'children' => [
            'atmospheric' => [
                'default'  => [['temperature_at' => 20, 'time_in_minutes' => 7, 'is_calculated' => false]],
                'children' => [
                    'EP' => [
                        'default'  => [['temperature_at' => 20, 'time_in_minutes' => 30, 'is_calculated' => false]],
                        'children' => [],
                    ],
                ],
            ],
        ],
    ];

    $this->assertSame($expected, json_decode(json_encode($tree), true));
}

public function testFromArrayRestoresNestedStructure(): void
{
    $raw = [
        'default'  => [['temperature_at' => 20, 'time_in_minutes' => 14, 'is_calculated' => false]],
        'children' => [
            'atmospheric' => [
                'default'  => [['temperature_at' => 20, 'time_in_minutes' => 7, 'is_calculated' => false]],
                'children' => [
                    'EP' => [
                        'default'  => [['temperature_at' => 20, 'time_in_minutes' => 30, 'is_calculated' => false]],
                        'children' => [],
                    ],
                ],
            ],
        ],
    ];

    $tree = RecoatingIntervalTree::fromArray($raw);

    $this->assertSame(14, $tree->default->points[0]->timeInMinutes);
    $this->assertSame(7,  $tree->children['atmospheric']->default->points[0]->timeInMinutes);
    $this->assertSame(30, $tree->children['atmospheric']->children['EP']->default->points[0]->timeInMinutes);
    $this->assertSame($raw, json_decode(json_encode($tree), true));
}

public function testFromArrayWithoutChildrenKey(): void
{
    $raw = [
        'default' => [['temperature_at' => 20, 'time_in_minutes' => 14, 'is_calculated' => false]],
        // ключа children может не быть
    ];

    $tree = RecoatingIntervalTree::fromArray($raw);

    $this->assertSame([], $tree->children);
}
```

- [ ] **Step 6: Запустить — все тесты PASS (реализация уже на месте)**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalTreeTest.php`
Ожидание: PASS все 8 тестов.

- [ ] **Step 7: Остановиться — попросить пользователя закоммитить**

Сообщить: «Task 3 готов: `RecoatingIntervalTree` с валидацией структуры + jsonSerialize / fromArray, 8 тестов зелёные.»
Дождаться ответа.

---

## Task 4: DBAL Type + регистрация в `doctrine.yaml`

**Цель:** Доктрина может сериализовать/десериализовать `RecoatingIntervalTree` ↔ JSONB.

**Файлы:**
- Create: `app/src/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalTreeType.php`
- Modify: `app/config/packages/doctrine.yaml`
- Create: `app/tests/Unit/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalTreeTypeTest.php`

- [ ] **Step 1: Написать падающий тест DBAL round-trip**

Создать `app/tests/Unit/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalTreeTypeTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Infrastructure\Database\DBAL\RecoatingIntervalTreeType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

final class RecoatingIntervalTreeTypeTest extends TestCase
{
    private RecoatingIntervalTreeType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new RecoatingIntervalTreeType();
        $this->platform = new PostgreSQLPlatform();
    }

    public function testRoundTripLeaf(): void
    {
        $tree = new RecoatingIntervalTree(
            new DryingTimeSeries(new TimeAtTemperature(20, 14)),
        );

        $db = $this->type->convertToDatabaseValue($tree, $this->platform);
        $restored = $this->type->convertToPHPValue($db, $this->platform);

        $this->assertInstanceOf(RecoatingIntervalTree::class, $restored);
        $this->assertSame(
            json_decode(json_encode($tree), true),
            json_decode(json_encode($restored), true),
        );
    }

    public function testRoundTripNested(): void
    {
        $tree = new RecoatingIntervalTree(
            new DryingTimeSeries(new TimeAtTemperature(20, 14)),
            [
                'atmospheric' => new RecoatingIntervalTree(
                    new DryingTimeSeries(new TimeAtTemperature(20, 7)),
                    [
                        'EP' => new RecoatingIntervalTree(
                            new DryingTimeSeries(new TimeAtTemperature(20, 30)),
                        ),
                    ],
                ),
            ],
        );

        $db = $this->type->convertToDatabaseValue($tree, $this->platform);
        $restored = $this->type->convertToPHPValue($db, $this->platform);

        $this->assertSame(
            json_decode(json_encode($tree), true),
            json_decode(json_encode($restored), true),
        );
    }

    public function testNullValueRoundTrips(): void
    {
        $this->assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        $this->assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testRejectsWrongPhpType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->type->convertToDatabaseValue('not a tree', $this->platform);
    }
}
```

- [ ] **Step 2: Запустить — FAIL**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalTreeTypeTest.php`
Ожидание: FAIL — class not found.

- [ ] **Step 3: Создать DBAL Type**

Создать `app/src/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalTreeType.php`:

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

final class RecoatingIntervalTreeType extends JsonType
{
    public const NAME = 'recoating_interval_tree';

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof RecoatingIntervalTree) {
            throw new \InvalidArgumentException(sprintf(
                'Ожидался RecoatingIntervalTree, передан %s.',
                is_object($value) ? $value::class : gettype($value),
            ));
        }
        return parent::convertToDatabaseValue($value->jsonSerialize(), $platform);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?RecoatingIntervalTree
    {
        if ($value === null) {
            return null;
        }
        $raw = parent::convertToPHPValue($value, $platform);
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Для RecoatingIntervalTree ожидается JSON-объект.');
        }
        return RecoatingIntervalTree::fromArray($raw);
    }
}
```

- [ ] **Step 4: Запустить — PASS**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalTreeTypeTest.php`
Ожидание: PASS все 4 теста.

- [ ] **Step 5: Зарегистрировать тип в `doctrine.yaml`**

В `app/config/packages/doctrine.yaml` под `doctrine.dbal.types` добавить строку рядом с `drying_time_series`:

```yaml
        types:
            drying_time_series: App\Coatings\Infrastructure\Database\DBAL\DryingTimeSeriesType
            dft_range: App\Coatings\Infrastructure\Database\DBAL\DftRangeType
            recoating_interval_tree: App\Coatings\Infrastructure\Database\DBAL\RecoatingIntervalTreeType
```

- [ ] **Step 6: Остановиться — попросить пользователя закоммитить**

Сообщить: «Task 4 готов: `RecoatingIntervalTreeType` зарегистрирован, round-trip протестирован. Прошу закоммитить.»
Дождаться ответа.

---

## Task 5: `Coating` — поля, setters, shortcut методы и helper

**Цель:** Заменить тип полей `*RecoatingInterval` в `Coating`, переписать setters/getters, добавить 4 типизированных shortcut-метода и приватный helper `descendRecoating`. Поле `min` теперь — `RecoatingIntervalTree`, поле `max` — `?RecoatingIntervalTree`.

**Файлы:**
- Modify: `app/src/Coatings/Domain/Aggregate/Coating/Coating.php`
- Create: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php`

Этот task делается **в два захода**: сначала меняем типы (Step 1–4 — компилируется и старые тесты переходят на новый тип), потом добавляем shortcut-методы (Step 5–8 — тесты на каскад fallback'ов).

### Часть A: смена типов полей

- [ ] **Step 1: Поменять типы полей и сигнатуры setter'ов/getter'ов в `Coating`**

В `Coating.php`:

1. Заменить декларации полей:

```php
    private RecoatingIntervalTree $minRecoatingInterval;
    private ?RecoatingIntervalTree $maxRecoatingInterval;
```

2. В конструкторе поменять параметры:

```php
        RecoatingIntervalTree $minRecoatingInterval,
        ?RecoatingIntervalTree $maxRecoatingInterval,
```

3. Поменять сигнатуры setter'ов и getter'ов:

```php
    public function getMinRecoatingInterval(): RecoatingIntervalTree { return $this->minRecoatingInterval; }

    public function getMaxRecoatingInterval(): ?RecoatingIntervalTree { return $this->maxRecoatingInterval; }

    public function setMinRecoatingInterval(RecoatingIntervalTree $minRecoatingInterval): void
    {
        $this->minRecoatingInterval = $minRecoatingInterval;
    }

    public function setMaxRecoatingInterval(?RecoatingIntervalTree $maxRecoatingInterval): void
    {
        $this->maxRecoatingInterval = $maxRecoatingInterval;
    }
```

- [ ] **Step 2: Поменять ORM XML mapping**

В `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml` заменить тип у двух полей:

```xml
        <field name="minRecoatingInterval" column="min_recoating_interval" type="recoating_interval_tree"/>
        <field name="maxRecoatingInterval" column="max_recoating_interval" type="recoating_interval_tree" nullable="true"/>
```

- [ ] **Step 3: Подстроить вызовы в `CoatingMaker`**

В `app/src/Coatings/Domain/Service/CoatingMaker.php` поменять сигнатуру `make()` — она остаётся семантически такой же, просто типы:

```php
    public function make(
        string                 $title,
        string                 $description,
        int                    $volumeSolid,
        float                  $massDensity,
        CoatingBase            $base,
        DftRange               $dftRange,
        int                    $applicationMinTemp,
        DryingTimeSeries       $dryToTouch,
        DryingTimeSeries       $fullCure,
        RecoatingIntervalTree  $minRecoatingInterval,
        ?RecoatingIntervalTree $maxRecoatingInterval,
        string                 $manufacturerId,
        array                  $coatingTagIds,
        float                  $pack,
        ?string                $thinner,
    ): Coating {
```

Также удалить старый `use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries` если он стал избыточен (он остаётся — `dryToTouch` и `fullCure` всё ещё `DryingTimeSeries`). Добавить `use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;`.

- [ ] **Step 4: Подстроить handlers — оборачивать DryingTimeSeries в leaf-Tree перед передачей в Coating**

В `CreateCoatingCommandHandler.php`:

1. Добавить импорт `use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;`.
2. В `__invoke()` заменить аргументы про recoating-интервалы:

```php
            $this->buildDryingTimeSeries($dto->dryToTouch),
            $this->buildDryingTimeSeries($dto->fullCure),
            new RecoatingIntervalTree($this->buildDryingTimeSeries($dto->minRecoatingInterval)),
            $dto->maxRecoatingInterval !== null
                ? new RecoatingIntervalTree($this->buildDryingTimeSeries($dto->maxRecoatingInterval))
                : null,
```

В `UpdateCoatingCommandHandler.php`:

1. Добавить импорт `use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;`.
2. Поменять оба setter-вызова:

```php
        if (!empty($dto->minRecoatingInterval)) {
            $coating->setMinRecoatingInterval(
                new RecoatingIntervalTree($this->buildDryingTimeSeries($dto->minRecoatingInterval)),
            );
        }
        // maxRecoatingInterval=null означает «без верхней границы»; пустой массив трактуем так же.
        $coating->setMaxRecoatingInterval(
            empty($dto->maxRecoatingInterval)
                ? null
                : new RecoatingIntervalTree($this->buildDryingTimeSeries($dto->maxRecoatingInterval)),
        );
```

Также подправить `CoatingDTOTransformer.php`: метод `fromEntity()` сейчас вызывает `$entity->getMinRecoatingInterval()` и ожидает `DryingTimeSeries`. Теперь получает Tree, нужно достать `default`:

```php
        $dto->minRecoatingInterval = $this->pointsFromSeries($entity->getMinRecoatingInterval()->default);
        $dto->maxRecoatingInterval = $entity->getMaxRecoatingInterval() !== null
            ? $this->pointsFromSeries($entity->getMaxRecoatingInterval()->default)
            : null;
```

- [ ] **Step 5: Прогон тестов уровня Unit — старые тесты должны проходить**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/Coatings/`
Ожидание: все тесты PASS. Если падает старый — значит он напрямую дёргает getMinRecoatingInterval() как Series; исправить тест, чтобы шёл через `->default`.

### Часть B: shortcut-методы и helper

- [ ] **Step 6: Написать падающие тесты shortcut-методов**

Создать `app/tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\Specification\UniqueTitleCoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use PHPUnit\Framework\TestCase;

final class CoatingTest extends TestCase
{
    public function testMinRecoatingForFallsBackToRootDefaultWhenNoBranches(): void
    {
        $globalDefault = new DryingTimeSeries(new TimeAtTemperature(20, 14 * 24 * 60));
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree($globalDefault),
            max: null,
        );

        $series = $coating->minRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP);

        $this->assertSame($globalDefault, $series);
    }

    public function testMaxRecoatingForReturnsNullWhenMaxIsAbsent(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );

        $this->assertNull(
            $coating->maxRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP),
        );
    }

    public function testMaxRecoatingForUsesEnvDefaultWhenTopcoatMissing(): void
    {
        $rootDef = new DryingTimeSeries(new TimeAtTemperature(20, 14 * 24 * 60));
        $atmDef  = new DryingTimeSeries(new TimeAtTemperature(20, 7  * 24 * 60));
        $max = new RecoatingIntervalTree(
            $rootDef,
            ['atmospheric' => new RecoatingIntervalTree($atmDef)],
        );
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: $max,
        );

        $series = $coating->maxRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP);

        $this->assertSame($atmDef, $series, 'EP не задан → возвращаем дефолт среды');
    }

    public function testMaxRecoatingForReturnsTopcoatLeafWhenPresent(): void
    {
        $rootDef = new DryingTimeSeries(new TimeAtTemperature(20, 14 * 24 * 60));
        $atmDef  = new DryingTimeSeries(new TimeAtTemperature(20, 7  * 24 * 60));
        $epDef   = new DryingTimeSeries(new TimeAtTemperature(20, 30 * 24 * 60));
        $max = new RecoatingIntervalTree(
            $rootDef,
            [
                'atmospheric' => new RecoatingIntervalTree(
                    $atmDef,
                    ['EP' => new RecoatingIntervalTree($epDef)],
                ),
            ],
        );
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: $max,
        );

        $this->assertSame(
            $epDef,
            $coating->maxRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP),
        );
    }

    public function testMaxRecoatingForFallsBackToRootWhenEnvMissing(): void
    {
        $rootDef = new DryingTimeSeries(new TimeAtTemperature(20, 14 * 24 * 60));
        $atmDef  = new DryingTimeSeries(new TimeAtTemperature(20, 7  * 24 * 60));
        $max = new RecoatingIntervalTree(
            $rootDef,
            ['atmospheric' => new RecoatingIntervalTree($atmDef)],
        );
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: $max,
        );

        $this->assertSame(
            $rootDef,
            $coating->maxRecoatingFor(EnvironmentType::Special, CoatingBase::EP),
            'Special-ветки нет → корневой default',
        );
    }

    public function testMaxRecoatingPointAtAppliesGetPointToFoundSeries(): void
    {
        $epSeries = new DryingTimeSeries(
            new TimeAtTemperature(20, 30 * 24 * 60),
            new TimeAtTemperature(30, 15 * 24 * 60),
        );
        $max = new RecoatingIntervalTree(
            new DryingTimeSeries(new TimeAtTemperature(20, 14 * 24 * 60)),
            [
                'atmospheric' => new RecoatingIntervalTree(
                    new DryingTimeSeries(new TimeAtTemperature(20, 7 * 24 * 60)),
                    ['EP' => new RecoatingIntervalTree($epSeries)],
                ),
            ],
        );
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: $max,
        );

        $point = $coating->maxRecoatingPointAt(EnvironmentType::Atmospheric, CoatingBase::EP, 20);
        $this->assertNotNull($point);
        $this->assertSame(30 * 24 * 60, $point->timeInMinutes);
    }

    public function testMaxRecoatingPointAtReturnsNullWhenMaxIsAbsent(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );

        $this->assertNull(
            $coating->maxRecoatingPointAt(EnvironmentType::Atmospheric, CoatingBase::EP, 20),
        );
    }

    private function makeCoating(
        RecoatingIntervalTree $min,
        ?RecoatingIntervalTree $max,
    ): Coating {
        $manufacturer = $this->createMock(Manufacturer::class);
        $manufacturer->method('getId')->willReturn('00000000-0000-0000-0000-000000000001');

        $spec = $this->createMock(CoatingSpecification::class);
        $spec->uniqueTitleCoatingSpecification = $this->createMock(UniqueTitleCoatingSpecification::class);

        return new Coating(
            UuidService::generateUuid(),
            'Test Coating',
            'desc',
            50,
            1.2,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(80, 150), 100, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 24 * 60)),
            $min,
            $max,
            1.0,
            null,
            $manufacturer,
            $spec,
        );
    }
}
```

- [ ] **Step 7: Запустить — FAIL (методов нет)**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php`
Ожидание: FAIL — методы `minRecoatingFor` / `maxRecoatingFor` / `maxRecoatingPointAt` не существуют.

- [ ] **Step 8: Добавить в `Coating` helper и 4 shortcut-метода**

В `Coating.php` добавить импорты (если их нет — `EnvironmentType`, `TimeAtTemperature`) и в конце класса:

```php
    public function minRecoatingFor(EnvironmentType $env, CoatingBase $topcoat): DryingTimeSeries
    {
        return $this->descendRecoating($this->minRecoatingInterval, $env, $topcoat);
    }

    public function maxRecoatingFor(EnvironmentType $env, CoatingBase $topcoat): ?DryingTimeSeries
    {
        return $this->maxRecoatingInterval === null
            ? null
            : $this->descendRecoating($this->maxRecoatingInterval, $env, $topcoat);
    }

    public function minRecoatingPointAt(EnvironmentType $env, CoatingBase $topcoat, int $temperature): ?TimeAtTemperature
    {
        return $this->minRecoatingFor($env, $topcoat)->getPoint($temperature);
    }

    public function maxRecoatingPointAt(EnvironmentType $env, CoatingBase $topcoat, int $temperature): ?TimeAtTemperature
    {
        return $this->maxRecoatingFor($env, $topcoat)?->getPoint($temperature);
    }

    private function descendRecoating(
        RecoatingIntervalTree $tree,
        EnvironmentType $env,
        CoatingBase $topcoat,
    ): DryingTimeSeries {
        $envNode     = $tree->children[$env->value] ?? null;
        $topcoatNode = $envNode?->children[$topcoat->value] ?? null;

        return $topcoatNode?->default
            ?? $envNode?->default
            ?? $tree->default;
    }
```

- [ ] **Step 9: Запустить — все тесты PASS**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php`
Ожидание: PASS все 7 тестов.

- [ ] **Step 10: Прогнать всю unit-сюиту Coatings — ничего не должно сломаться**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/Coatings/`
Ожидание: PASS.

- [ ] **Step 11: Остановиться — попросить пользователя закоммитить**

Сообщить: «Task 5 готов: типы полей `Coating` поменяны на дерево, ORM mapping обновлён, handlers оборачивают serie в leaf-tree, CoatingDTOTransformer достаёт default. 4 типизированных shortcut-метода + приватный helper. Все unit-тесты Coatings зелёные.»
Дождаться ответа.

---

## Task 6: Doctrine migration данных

**Цель:** Преобразовать существующие JSONB-значения колонок `min_recoating_interval`/`max_recoating_interval` из формы `[массив точек]` в форму `{default: [массив точек], children: {}}`.

**Файлы:**
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version<TIMESTAMP>.php` (см. пояснение по timestamp ниже)

- [ ] **Step 1: Создать файл миграции вручную (по образцу существующих)**

Узнать текущий timestamp в формате `YYYYMMDDHHMMSS`:
```bash
date +%Y%m%d%H%M%S
```
Например, получили `20260616123000`.

Создать файл `app/src/Shared/Infrastructure/Database/Migrations/Version20260616123000.php` (с реальным timestamp) с шаблоном по образцу `Version20260607081726.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wrap coatings_coating recoating interval columns into RecoatingIntervalTree shape.';
    }

    public function up(Schema $schema): void
    {
        // заполнить в Step 2
    }

    public function down(Schema $schema): void
    {
        // заполнить в Step 2
    }
}
```

Имя класса должно совпадать с именем файла.

- [ ] **Step 2: Прописать SQL миграции**

Внутри сгенерированного файла:

```php
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE coatings_coating
            SET
              min_recoating_interval = jsonb_build_object(
                'default',  min_recoating_interval,
                'children', '{}'::jsonb
              ),
              max_recoating_interval = CASE
                WHEN max_recoating_interval IS NULL THEN NULL
                ELSE jsonb_build_object(
                  'default',  max_recoating_interval,
                  'children', '{}'::jsonb
                )
              END
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE coatings_coating
            SET
              min_recoating_interval = min_recoating_interval->'default',
              max_recoating_interval = CASE
                WHEN max_recoating_interval IS NULL THEN NULL
                ELSE max_recoating_interval->'default'
              END
        SQL);
    }
```

(Имя таблицы `coatings_coating` берётся из ORM mapping; проверить — оно там же в `<entity ... table="coatings_coating">`.)

- [ ] **Step 3: Применить миграцию на dev-окружении**

Запустить из `app/`:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

Ожидание: success message, миграция применилась.

Проверка через psql или admin tool: значение в колонке `min_recoating_interval` теперь имеет вид `{"default": [...], "children": {}}`.

- [ ] **Step 4: Прогнать smoke-тест — открыть список покрытий в админке**

В браузере открыть страницу списка покрытий, открыть редактирование одного из них, убедиться что данные подгружаются и форма заполнена корректно.

Если в проекте есть functional/integration-тесты Coatings — запустить:

```bash
cd app && vendor/bin/phpunit tests/Functional/Coatings/ 2>/dev/null || true
```

Если такого пути нет — пропустить.

- [ ] **Step 5: Откатить миграцию и проверить down**

Запустить: `cd app && php bin/console doctrine:migrations:migrate prev --no-interaction`
Ожидание: success. Значения в колонках вернулись к прежней плоской форме.

- [ ] **Step 6: Накатить обратно**

Запустить: `cd app && php bin/console doctrine:migrations:migrate --no-interaction`
Ожидание: success, форма снова `{default, children}`.

- [ ] **Step 7: Остановиться — попросить пользователя закоммитить**

Сообщить: «Task 6 готов: миграция `Version<TIMESTAMP>` оборачивает существующие значения, проверена round-trip up/down. Прошу закоммитить.»
Дождаться ответа.

---

## Task 7: Финальная проверка

**Цель:** Убедиться, что ничего не сломано, изменения корректно ложатся в текущую ветку `refactor/coating-vo`.

- [ ] **Step 1: Полный прогон unit-тестов**

Запустить: `cd app && vendor/bin/phpunit tests/Unit/`
Ожидание: все тесты PASS.

- [ ] **Step 2: Прогон тестов всех модулей**

Запустить: `cd app && vendor/bin/phpunit`
Ожидание: PASS.

- [ ] **Step 3: Статический анализ (если в проекте есть PHPStan)**

Запустить: `cd app && vendor/bin/phpstan analyze src/Coatings/ tests/Unit/Coatings/ 2>/dev/null` (если конфиг отсутствует — пропустить).

- [ ] **Step 4: Краткий smoke-test админки**

Создание нового покрытия через форму, редактирование существующего, сохранение, чтение значений в редактирование (round-trip данных через форму).

- [ ] **Step 5: Сообщить пользователю об окончании**

Сообщить: «План выполнен полностью. Дерево `RecoatingIntervalTree` живёт в `Coating`, поиск с fallback через `min/maxRecoatingFor`. Следующий шаг по дорожной карте — UI overrides (отдельный спек) и интеграция в `CoatingSystem`.»

---

## Сводка контрактов (для контроля согласованности)

Эти типы и сигнатуры должны совпадать между всеми задачами:

| Сущность | Где введена | Сигнатура |
|---|---|---|
| `RecoatingIntervalTree::__construct` | Task 3 | `(DryingTimeSeries $default, array<string, self> $children = [])` |
| `RecoatingIntervalTree::jsonSerialize` | Task 3 | `(): array{default: array, children: array<string, array>}` |
| `RecoatingIntervalTree::fromArray` | Task 3 | `static (array): self` |
| `DryingTimeSeries::fromArray` | Task 1 | `static (list<array<string,mixed>>): self` |
| `EnvironmentType` values | Task 2 | `'atmospheric' | 'immersion' | 'special'` |
| `Coating::minRecoatingFor` | Task 5 | `(EnvironmentType, CoatingBase): DryingTimeSeries` |
| `Coating::maxRecoatingFor` | Task 5 | `(EnvironmentType, CoatingBase): ?DryingTimeSeries` |
| `Coating::minRecoatingPointAt` | Task 5 | `(EnvironmentType, CoatingBase, int): ?TimeAtTemperature` |
| `Coating::maxRecoatingPointAt` | Task 5 | `(EnvironmentType, CoatingBase, int): ?TimeAtTemperature` |
| `Coating::descendRecoating` | Task 5 | `private (RecoatingIntervalTree, EnvironmentType, CoatingBase): DryingTimeSeries` |
| `Coating::$minRecoatingInterval` | Task 5 | `RecoatingIntervalTree` |
| `Coating::$maxRecoatingInterval` | Task 5 | `?RecoatingIntervalTree` |
| `CoatingMaker::make(...$minRecoatingInterval, $maxRecoatingInterval, ...)` | Task 5 | `RecoatingIntervalTree`, `?RecoatingIntervalTree` |
| ORM type `min_recoating_interval` / `max_recoating_interval` | Task 5 | `recoating_interval_tree` |
| Doctrine DBAL type name | Task 4 | `recoating_interval_tree` |
| JSONB форма узла | Task 3, 6 | `{"default": [<points>], "children": {<key>: <node>}}` |
