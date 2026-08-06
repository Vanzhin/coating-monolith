# Причёсывание Coatings-экшенов — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Превратить разжиревшие Coatings-экшены в тонкие оркестраторы: парсинг query и сборку фильтра — в маперы, render-payload — в view-factory, бизнес-throw'ы убрать из контроллеров, унифицировать идиому чтения query.

**Architecture:** Read-side: `*ListRequestMapper::filterFromRequest(Request): Filter` + `*ListViewFactory::build(...): array`, экшен только диспатчит query и рендерит. Write-side: shape слоёв — в `CoatingSystemMapper::layersFromInput`, повторную гидрацию тайпхедов после POST-ошибки — в общий `CoatingSystemFormRehydrator`; инвариант толщины остаётся в домене. Общий `Shared/Infrastructure/Helper/QueryParams` убивает две разные идиомы чтения query.

**Tech Stack:** PHP 8.x, Symfony (HttpFoundation, Validator, autowire), Doctrine, PHPUnit. DDD + Hexagonal.

## Global Constraints

- Бизнес-проверки только в домене. В контроллерах/маперах — ноль `throw` про доменные правила (CLAUDE.md).
- Mapper = pure shape mapping (форма/query ↔ DTO/Filter), без бизнес-фильтров и без DB-лукапов. DB-лукапы (гидрация тайпхедов) — отдельный сервис, не мапер.
- Экшен — оркестратор: собрал вход, дёрнул query/command, отдал ответ. Никакой бизнес-логики.
- `AppException` (Shared\Infrastructure\Exception) — HTTP 422, сообщение на русском.
- Не изобретать стили/шаблоны: view-factory отдаёт тот же payload, Twig не трогаем.
- Тесты зеркалят `src/`: `app/tests/Unit/{Context}/...`, namespace `App\Tests\Unit\...`, класс `PHPUnit\Framework\TestCase`, методы `test_snake_case`.
- Автовайринг включён — новые классы под `App\Coatings\...`/`App\Shared\...` регистрируются автоматически, `services.yaml` не править.
- Коммиты: одна строка, ≤150 символов, жёлтая пресса по делу, без ID задачи (рефактор), без тела, без Co-Authored-By трейлера (release-notes лимит ~3000 символов — CLAUDE.md). Коммитим только в рамках SDD (по задаче), вне SDD git — на пользователе.
- Запуск тестов из `app/`: `vendor/bin/phpunit <path>`.

## Setup (до Task 1)

- [ ] Создать ветку: `git checkout -b refactor/coatings-actions-cleanup`
- [ ] Зафиксировать baseline: `cd app && vendor/bin/phpunit tests/Unit/Coatings tests/Functional/Coatings` — всё зелёное. Если красное — стоп, чиним/сообщаем до начала.

## File Structure

**Новые:**
- `app/src/Shared/Infrastructure/Helper/QueryParams.php` — чтение query-параметров (nullableInt, stringCollection, intRange).
- `app/src/Coatings/Infrastructure/Mapper/CoatingListRequestMapper.php` — query → `CoatingsFilter`.
- `app/src/Coatings/Infrastructure/Mapper/CoatingSystemListRequestMapper.php` — query → `CoatingSystemsFilter`.
- `app/src/Coatings/Infrastructure/View/CoatingRangePresets.php` — UI-пресеты диапазонов (const + геттеры).
- `app/src/Coatings/Infrastructure/View/CoatingListViewFactory.php` — full render-payload для списка покрытий.
- `app/src/Coatings/Infrastructure/View/CoatingSystemListViewFactory.php` — full render-payload для списка систем.
- `app/src/Coatings/Application/Service/CoatingSystemFormRehydrator.php` — гидрация тайпхедов inputData после POST-ошибки.

**Меняются (худеют):**
- `app/src/Coatings/Infrastructure/Controller/Coating/ListAction.php` (237 → ~40)
- `app/src/Coatings/Infrastructure/Controller/CoatingSystem/ListAction.php` (133 → ~35)
- `app/src/Coatings/Infrastructure/Controller/CoatingSystem/AddAction.php`, `UpdateAction.php`
- `app/src/Coatings/Infrastructure/Mapper/CoatingSystemMapper.php` (+ `layersFromInput`)

**Тесты (новые):**
- `app/tests/Unit/Shared/Infrastructure/Helper/QueryParamsTest.php`
- `app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingListRequestMapperTest.php`
- `app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingSystemListRequestMapperTest.php`
- `app/tests/Unit/Coatings/Infrastructure/View/CoatingListViewFactoryTest.php` (smoke)
- `app/tests/Unit/Coatings/Infrastructure/View/CoatingSystemListViewFactoryTest.php` (smoke)
- `app/tests/Functional/Coatings/Infrastructure/Controller/Coating/ListActionTest.php` (safety net — сейчас его нет)
- `app/tests/Functional/Coatings/Infrastructure/Service/CoatingSystemFormRehydratorTest.php`
- `+ CoatingSystemMapperTest` — новый тест-метод на `layersFromInput`.

---

### Task 1: `QueryParams` — общий хелпер чтения query

**Files:**
- Create: `app/src/Shared/Infrastructure/Helper/QueryParams.php`
- Test: `app/tests/Unit/Shared/Infrastructure/Helper/QueryParamsTest.php`

**Interfaces:**
- Consumes: `Symfony\Component\HttpFoundation\Request`, `App\Shared\Domain\Aggregate\Collection\StringCollection`, `App\Shared\Domain\Repository\RangeFilter`.
- Produces:
  - `QueryParams::nullableInt(Request $request, string $key): ?int` — пустая строка/отсутствие → null, иначе `(int)`.
  - `QueryParams::stringCollection(Request $request, string $key, ?callable $isValid = null, bool $unique = false): StringCollection` — читает `$request->query->all($key)`, оставляет строки, опционально фильтрует `$isValid(string): bool`, опционально дедупит.
  - `QueryParams::intRange(Request $request, string $fromKey, string $toKey, int $multiplier = 1, bool $dropInverted = true): ?RangeFilter` — читает from/to через `nullableInt`, умножает на multiplier; при `dropInverted=true` инвертированный диапазон → null (политика списка систем), при `false` делегирует в `RangeFilter::tryFromNullable`, который кидает `AppException` на from>to (политика списка покрытий).

- [ ] **Step 1: Написать падающий тест**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Helper;

use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Helper\QueryParams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class QueryParamsTest extends TestCase
{
    private QueryParams $qp;

    protected function setUp(): void
    {
        $this->qp = new QueryParams();
    }

    public function test_nullable_int_returns_null_for_missing_and_empty(): void
    {
        $request = Request::create('/', 'GET', ['a' => '', 'b' => '  ']);

        self::assertNull($this->qp->nullableInt($request, 'a'));
        self::assertNull($this->qp->nullableInt($request, 'b'));
        self::assertNull($this->qp->nullableInt($request, 'missing'));
    }

    public function test_nullable_int_casts_present_value(): void
    {
        $request = Request::create('/', 'GET', ['n' => '42']);

        self::assertSame(42, $this->qp->nullableInt($request, 'n'));
    }

    public function test_string_collection_filters_and_dedups(): void
    {
        $request = Request::create('/', 'GET', ['ids' => ['EP', 'ZZZ', 'EP', 'AY']]);

        $valid = $this->qp->stringCollection(
            $request,
            'ids',
            static fn (string $v): bool => in_array($v, ['EP', 'AY'], true),
            unique: true,
        );

        self::assertSame(['EP', 'AY'], $valid->getList());
    }

    public function test_string_collection_without_validator_keeps_all_strings(): void
    {
        $request = Request::create('/', 'GET', ['ids' => ['a', 'b']]);

        self::assertSame(['a', 'b'], $this->qp->stringCollection($request, 'ids')->getList());
    }

    public function test_int_range_applies_multiplier(): void
    {
        $request = Request::create('/', 'GET', ['from' => '2', 'to' => '3']);

        $range = $this->qp->intRange($request, 'from', 'to', 60);

        self::assertNotNull($range);
        self::assertSame(120, $range->from);
        self::assertSame(180, $range->to);
    }

    public function test_int_range_drops_inverted_by_default(): void
    {
        $request = Request::create('/', 'GET', ['from' => '10', 'to' => '5']);

        self::assertNull($this->qp->intRange($request, 'from', 'to'));
    }

    public function test_int_range_throws_on_inverted_when_not_dropping(): void
    {
        $request = Request::create('/', 'GET', ['from' => '10', 'to' => '5']);

        $this->expectException(AppException::class);
        $this->qp->intRange($request, 'from', 'to', 1, dropInverted: false);
    }

    public function test_int_range_null_when_both_missing(): void
    {
        self::assertNull($this->qp->intRange(Request::create('/', 'GET'), 'from', 'to'));
    }
}
```

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `cd app && vendor/bin/phpunit tests/Unit/Shared/Infrastructure/Helper/QueryParamsTest.php`
Expected: FAIL — `Class "App\Shared\Infrastructure\Helper\QueryParams" not found`.

- [ ] **Step 3: Реализовать хелпер**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Helper;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\RangeFilter;
use Symfony\Component\HttpFoundation\Request;

/**
 * Единая идиома чтения query-параметров для мап-еров списков. Раньше каждый
 * list-экшен парсил query по-своему (nullableInt vs readRange) — тут одно место.
 * Только чтение/shape: приведение типов и построение RangeFilter из голых
 * границ. Доменные инварианты (границы фасета) — забота самого RangeFilter.
 */
final class QueryParams
{
    public function nullableInt(Request $request, string $key): ?int
    {
        $raw = $request->query->get($key);
        if (null === $raw || '' === trim((string) $raw)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * @param null|callable(string): bool $isValid
     */
    public function stringCollection(
        Request $request,
        string $key,
        ?callable $isValid = null,
        bool $unique = false,
    ): StringCollection {
        $values = array_values(array_filter(
            $request->query->all($key),
            static fn (mixed $v): bool => is_string($v),
        ));

        if (null !== $isValid) {
            $values = array_values(array_filter($values, $isValid));
        }
        if ($unique) {
            $values = array_values(array_unique($values));
        }

        return new StringCollection(...$values);
    }

    public function intRange(
        Request $request,
        string $fromKey,
        string $toKey,
        int $multiplier = 1,
        bool $dropInverted = true,
    ): ?RangeFilter {
        $from = $this->nullableInt($request, $fromKey);
        $to = $this->nullableInt($request, $toKey);
        $from = null !== $from ? $from * $multiplier : null;
        $to = null !== $to ? $to * $multiplier : null;

        // dropInverted=true: инвертированный диапазон тихо роняем (список систем).
        // dropInverted=false: делегируем в RangeFilter — он кинет AppException на
        // from>to (список покрытий показывает ошибку в форме, а не молчит).
        if ($dropInverted && null !== $from && null !== $to && $from > $to) {
            return null;
        }

        return RangeFilter::tryFromNullable($from, $to);
    }
}
```

- [ ] **Step 4: Запустить — зелёное**

Run: `cd app && vendor/bin/phpunit tests/Unit/Shared/Infrastructure/Helper/QueryParamsTest.php`
Expected: PASS (4 теста).

- [ ] **Step 5: Коммит**

```bash
git add app/src/Shared/Infrastructure/Helper/QueryParams.php app/tests/Unit/Shared/Infrastructure/Helper/QueryParamsTest.php
git commit -m "Общий QueryParams: одна идиома чтения query вместо двух разных в list-экшенах"
```

---

### Task 2: `CoatingListRequestMapper` — query → CoatingsFilter

**Files:**
- Create: `app/src/Coatings/Infrastructure/Mapper/CoatingListRequestMapper.php`
- Test: `app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingListRequestMapperTest.php`

**Interfaces:**
- Consumes: `QueryParams` (Task 1), `Request`, `CoatingsFilter`, `RangeFilter`, `SearchQuery`, `CoatingSort`, `CoatingBase`, `ThermalEnvironment`, `Pager`, `StringCollection`.
- Produces: `CoatingListRequestMapper::filterFromRequest(Request $request): CoatingsFilter`. Конвертация UI→минуты: `minRecoat20*` в часах (× 60), `maxRecoat20*` в днях (× 1440). Инвертированный диапазон → `RangeFilter::tryFromNullable` кидает `AppException` (поведение Coating-списка сохраняется, ловит экшен).

- [ ] **Step 1: Написать падающий тест**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Mapper;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Repository\CoatingSort;
use App\Coatings\Infrastructure\Mapper\CoatingListRequestMapper;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Helper\QueryParams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CoatingListRequestMapperTest extends TestCase
{
    private CoatingListRequestMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new CoatingListRequestMapper(new QueryParams());
    }

    public function test_empty_request_gives_empty_filter(): void
    {
        $filter = $this->mapper->filterFromRequest(Request::create('/', 'GET'));

        self::assertNull($filter->search);
        self::assertSame([], $filter->manufacturerIds->getList());
        self::assertSame([], $filter->baseValues->getList());
        self::assertNull($filter->minRecoating20);
        self::assertSame(CoatingSort::DEFAULT, $filter->sort);
    }

    public function test_base_values_are_enum_filtered_and_deduped(): void
    {
        $valid = CoatingBase::cases()[0]->value;
        $request = Request::create('/', 'GET', [
            'baseValues' => [$valid, 'GARBAGE', $valid],
        ]);

        $filter = $this->mapper->filterFromRequest($request);

        self::assertSame([$valid], $filter->baseValues->getList());
    }

    public function test_recoat_units_are_converted_to_minutes(): void
    {
        $request = Request::create('/', 'GET', [
            'minRecoat20From' => '2',   // часы → 120 минут
            'maxRecoat20To' => '3',     // дни → 4320 минут
        ]);

        $filter = $this->mapper->filterFromRequest($request);

        self::assertNotNull($filter->minRecoating20);
        self::assertSame(120, $filter->minRecoating20->from);
        self::assertNotNull($filter->maxRecoating20);
        self::assertSame(4320, $filter->maxRecoating20->to);
    }

    public function test_inverted_range_throws(): void
    {
        $request = Request::create('/', 'GET', [
            'appMinTempFrom' => '10',
            'appMinTempTo' => '5',
        ]);

        $this->expectException(AppException::class);
        $this->mapper->filterFromRequest($request);
    }

    public function test_unknown_sort_falls_back_to_default(): void
    {
        $request = Request::create('/', 'GET', ['sort' => 'nonsense']);

        self::assertSame(CoatingSort::DEFAULT, $this->mapper->filterFromRequest($request)->sort);
    }
}
```

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Mapper/CoatingListRequestMapperTest.php`
Expected: FAIL — класс `CoatingListRequestMapper` не найден.

- [ ] **Step 3: Реализовать мапер** (перенос блока 98–169 из `Coating/ListAction`)

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Mapper;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\CoatingSort;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Coatings\Domain\Repository\ThermalEnvironment;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\RangeFilter;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Component\HttpFoundation\Request;

/**
 * Query-параметры списка покрытий → доменный CoatingsFilter. Pure shape:
 * читает query, конвертирует UI-единицы в минуты, валидирует enum-значения.
 * Инварианты (границы диапазонов, температурный фасет) кидает домен при
 * конструировании фильтра — экшен ловит AppException и рендерит ошибку.
 */
final class CoatingListRequestMapper
{
    // UI задаёт интервал перекрытия: min в ЧАСАХ, max в ДНЯХ. Домен — в минутах.
    private const MINUTES_PER_HOUR = 60;
    private const MINUTES_PER_DAY = 1440;

    public function __construct(private readonly QueryParams $query)
    {
    }

    public function filterFromRequest(Request $request): CoatingsFilter
    {
        $search = $request->query->get('search');
        $thermEnvRaw = $request->query->get('thermEnv');
        $sortRaw = $request->query->get('sort');

        return new CoatingsFilter(
            search: SearchQuery::tryFromString(is_string($search) ? $search : null),
            manufacturerIds: $this->query->stringCollection($request, 'manufacturerIds'),
            pager: Pager::fromPage(
                $this->query->nullableInt($request, 'page'),
                $this->query->nullableInt($request, 'limit'),
            ),
            applicationMinTemp: RangeFilter::tryFromNullable(
                $this->query->nullableInt($request, 'appMinTempFrom'),
                $this->query->nullableInt($request, 'appMinTempTo'),
            ),
            volumeSolid: RangeFilter::tryFromNullable(
                $this->query->nullableInt($request, 'volumeSolidFrom'),
                $this->query->nullableInt($request, 'volumeSolidTo'),
            ),
            tagIds: $this->query->stringCollection($request, 'tagIds'),
            thermalTemperature: $this->query->nullableInt($request, 'thermTemp'),
            thermalEnvironment: is_string($thermEnvRaw) ? ThermalEnvironment::tryFrom($thermEnvRaw) : null,
            thermalIncludingPeak: $request->query->getBoolean('thermPeak'),
            sort: (is_string($sortRaw) ? CoatingSort::tryFrom($sortRaw) : null) ?? CoatingSort::DEFAULT,
            baseValues: $this->query->stringCollection(
                $request,
                'baseValues',
                static fn (string $v): bool => null !== CoatingBase::tryFrom($v),
                unique: true,
            ),
            minRecoating20: RangeFilter::tryFromNullable(
                $this->minutes($request, 'minRecoat20From', self::MINUTES_PER_HOUR),
                $this->minutes($request, 'minRecoat20To', self::MINUTES_PER_HOUR),
            ),
            maxRecoating20: RangeFilter::tryFromNullable(
                $this->minutes($request, 'maxRecoat20From', self::MINUTES_PER_DAY),
                $this->minutes($request, 'maxRecoat20To', self::MINUTES_PER_DAY),
            ),
        );
    }

    private function minutes(Request $request, string $key, int $factor): ?int
    {
        $value = $this->query->nullableInt($request, $key);

        return null !== $value ? $value * $factor : null;
    }
}
```

- [ ] **Step 4: Запустить — зелёное**

Run: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Mapper/CoatingListRequestMapperTest.php`
Expected: PASS (5 тестов).

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Infrastructure/Mapper/CoatingListRequestMapper.php app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingListRequestMapperTest.php
git commit -m "CoatingListRequestMapper: парсинг query и сборка CoatingsFilter уехали из 237-строчного экшена"
```

---

### Task 3: `CoatingRangePresets` + `CoatingListViewFactory`

**Files:**
- Create: `app/src/Coatings/Infrastructure/View/CoatingRangePresets.php`
- Create: `app/src/Coatings/Infrastructure/View/CoatingListViewFactory.php`
- Test: `app/tests/Unit/Coatings/Infrastructure/View/CoatingListViewFactoryTest.php`

**Interfaces:**
- Consumes: `QueryParams` (Task 1), `QueryBusInterface`, `TagRepositoryInterface`, `TagDTOTransformer`, `GetPagedManufacturersQuery`, `ManufacturersFilter`, `GetPagedCoatingsQueryResult`, `CoatingBase`, `CoatingSort`, `Request`.
- Produces:
  - `CoatingRangePresets` со статическими геттерами `appMinTemp(): array`, `volumeSolid(): array`, `minRecoat20(): array`, `maxRecoat20(): array`.
  - `CoatingListViewFactory::build(Request $request, GetPagedCoatingsQueryResult $result, ?string $error): array<string, mixed>` — full-payload шаблона `admin/coating/coating/index.html.twig`.

**Note:** partial-ветку экшен рендерит сам (тривиальна) — фабрика отвечает только за full-payload. `canEdit` в full-payload не входит (шаблон берёт через `app.user`) — воспроизводим текущий набор ключей 1-в-1 (строки 197–226 исходного экшена).

- [ ] **Step 1: Написать падающий smoke-тест**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\View;

use App\Coatings\Application\DTO\Tags\TagDTOTransformer;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;
use App\Coatings\Application\UseCase\Query\GetPagedManufacturers\GetPagedManufacturersQueryResult;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
use App\Coatings\Infrastructure\View\CoatingListViewFactory;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Helper\QueryParams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CoatingListViewFactoryTest extends TestCase
{
    public function test_build_returns_expected_payload_keys(): void
    {
        $queryBus = $this->createMock(QueryBusInterface::class);
        $queryBus->method('execute')->willReturn(new GetPagedManufacturersQueryResult([], new Pager(1, 1000)));

        $tagRepo = $this->createMock(TagRepositoryInterface::class);
        $tagRepo->method('findByIds')->willReturn([]);

        $factory = new CoatingListViewFactory(
            $queryBus,
            $tagRepo,
            new TagDTOTransformer(),
            new QueryParams(),
        );

        $result = new GetPagedCoatingsQueryResult([], new Pager(1, 20));
        $payload = $factory->build(Request::create('/', 'GET', ['appMinTempFrom' => '5']), $result, null);

        foreach ([
            'search', 'selectedManufacturerIds', 'selectedTags', 'selectedBaseValues',
            'manufacturers', 'result', 'error', 'coatingBases',
            'appMinTempPresets', 'volumeSolidPresets', 'minRecoat20Presets', 'maxRecoat20Presets',
            'appMinTempFrom', 'sort', 'sortOptions', 'preservedParams',
        ] as $key) {
            self::assertArrayHasKey($key, $payload);
        }
        self::assertSame(5, $payload['appMinTempFrom']);
        self::assertArrayNotHasKey('search', $payload['preservedParams']);
    }
}
```

*Примечание для исполнителя:* проверь фактическую сигнатуру конструктора `TagDTOTransformer` — если он требует зависимости, замени `new TagDTOTransformer()` на мок `$this->createMock(TagDTOTransformer::class)` с `method('fromEntityList')->willReturn([])`. Также сверь имя класса результата `GetPagedManufacturersQueryResult` и поле `manufacturers` (используется в исходном экшене как `$manufacturersResult->manufacturers`).

- [ ] **Step 2: Запустить — падает**

Run: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/View/CoatingListViewFactoryTest.php`
Expected: FAIL — классы `CoatingRangePresets`/`CoatingListViewFactory` не найдены.

- [ ] **Step 3a: Реализовать пресеты** (перенос констант 39–87 из `Coating/ListAction`)

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\View;

/**
 * UI-пресеты диапазонов для формы фильтра списка покрытий. Чисто визуальные
 * shortcut'ы: чип ставит слайдер в bounds и submit'ит форму с явными from/to.
 * Backend никакого «preset key» не знает — принимает голые from/to.
 */
final class CoatingRangePresets
{
    /** @return array<string, array{label: string, from: int, to: int}> */
    public static function appMinTemp(): array
    {
        return [
            'winter' => ['label' => 'Зимнее (ниже -5)', 'from' => -30, 'to' => -5],
            'standard' => ['label' => 'Стандартное (-5..+5)', 'from' => -5, 'to' => 5],
            'summer' => ['label' => 'Летнее (более +5)', 'from' => 5, 'to' => 50],
        ];
    }

    /** @return array<string, array{label: string, from: int, to: int}> */
    public static function volumeSolid(): array
    {
        return [
            'low' => ['label' => 'Низкий (≤ 40 %)', 'from' => 10, 'to' => 40],
            'medium' => ['label' => 'Средний (40–70 %)', 'from' => 40, 'to' => 70],
            'high' => ['label' => 'Высокий (≥ 70 %)', 'from' => 70, 'to' => 100],
        ];
    }

    /** Мин интервал перекрытия при +20 °C, в ЧАСАХ (верх 168 = 1 неделя).
     * @return array<string, array{label: string, from: int, to: int}> */
    public static function minRecoat20(): array
    {
        return [
            'fast' => ['label' => 'Быстрый (≤ 4 ч)', 'from' => 0, 'to' => 4],
            'standard' => ['label' => 'Стандарт (4–24 ч)', 'from' => 4, 'to' => 24],
            'slow' => ['label' => 'Медленный (1–3 сут)', 'from' => 24, 'to' => 72],
            'very_slow' => ['label' => 'Долгий (> 3 сут)', 'from' => 72, 'to' => 168],
        ];
    }

    /** Макс интервал перекрытия при +20 °C, в ДНЯХ (верх 365 = 1 год).
     * @return array<string, array{label: string, from: int, to: int}> */
    public static function maxRecoat20(): array
    {
        return [
            'day' => ['label' => '≤ 1 сут', 'from' => 0, 'to' => 1],
            'week' => ['label' => '1–7 сут', 'from' => 1, 'to' => 7],
            'month' => ['label' => '1–4 нед', 'from' => 7, 'to' => 28],
            'long' => ['label' => '> 4 нед', 'from' => 28, 'to' => 365],
        ];
    }
}
```

- [ ] **Step 3b: Реализовать view-factory** (перенос сборки payload + manufacturers/selectedTags из экшена)

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\View;

use App\Coatings\Application\DTO\Tags\TagDTOTransformer;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;
use App\Coatings\Application\UseCase\Query\GetPagedManufacturers\GetPagedManufacturersQuery;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Repository\CoatingSort;
use App\Coatings\Domain\Repository\ManufacturersFilter;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Component\HttpFoundation\Request;

/**
 * Собирает full render-payload шаблона списка покрытий. Тянет производителей и
 * выбранные теги, подмешивает пресеты и echo-back значений формы. Экшен остаётся
 * тонким: собрал фильтр → диспатч → отдал build(...) в render.
 */
final class CoatingListViewFactory
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly TagRepositoryInterface $coatingTagRepository,
        private readonly TagDTOTransformer $coatingTagDTOTransformer,
        private readonly QueryParams $query,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request, GetPagedCoatingsQueryResult $result, ?string $error): array
    {
        $manufacturers = $this->queryBus->execute(
            new GetPagedManufacturersQuery(new ManufacturersFilter(null, Pager::fromPage(1, 1000))),
        );

        $tagIds = $this->query->stringCollection($request, 'tagIds');
        $selectedTags = $this->coatingTagDTOTransformer->fromEntityList(
            $this->coatingTagRepository->findByIds($tagIds),
        );

        $search = $request->query->get('search');
        $thermEnvRaw = $request->query->get('thermEnv');
        $sortRaw = $request->query->get('sort');

        // Preserved: всё из URL, кроме того, что форма рендерит отдельно.
        $preservedParams = array_diff_key(
            $request->query->all(),
            array_flip(['search', 'page', 'partial']),
        );

        return [
            'search' => is_string($search) ? $search : '',
            'selectedManufacturerIds' => $this->query->stringCollection($request, 'manufacturerIds'),
            'selectedTags' => $selectedTags,
            'selectedBaseValues' => $this->query->stringCollection(
                $request,
                'baseValues',
                static fn (string $v): bool => null !== CoatingBase::tryFrom($v),
                unique: true,
            ),
            'manufacturers' => $manufacturers->manufacturers,
            'result' => $result,
            'error' => $error,
            'coatingBases' => CoatingBase::cases(),
            'appMinTempPresets' => CoatingRangePresets::appMinTemp(),
            'volumeSolidPresets' => CoatingRangePresets::volumeSolid(),
            'minRecoat20Presets' => CoatingRangePresets::minRecoat20(),
            'maxRecoat20Presets' => CoatingRangePresets::maxRecoat20(),
            'appMinTempFrom' => $this->query->nullableInt($request, 'appMinTempFrom'),
            'appMinTempTo' => $this->query->nullableInt($request, 'appMinTempTo'),
            'volumeSolidFrom' => $this->query->nullableInt($request, 'volumeSolidFrom'),
            'volumeSolidTo' => $this->query->nullableInt($request, 'volumeSolidTo'),
            'minRecoat20From' => $this->query->nullableInt($request, 'minRecoat20From'),
            'minRecoat20To' => $this->query->nullableInt($request, 'minRecoat20To'),
            'maxRecoat20From' => $this->query->nullableInt($request, 'maxRecoat20From'),
            'maxRecoat20To' => $this->query->nullableInt($request, 'maxRecoat20To'),
            'thermTemp' => $this->query->nullableInt($request, 'thermTemp'),
            'thermEnv' => is_string($thermEnvRaw) ? $thermEnvRaw : null,
            'thermIncludingPeak' => $request->query->getBoolean('thermPeak'),
            'sort' => (is_string($sortRaw) ? CoatingSort::tryFrom($sortRaw) : null) ?? CoatingSort::DEFAULT,
            'sortOptions' => CoatingSort::cases(),
            'preservedParams' => $preservedParams,
        ];
    }
}
```

*Примечание для исполнителя:* сверь исходный payload (`Coating/ListAction` строки 197–226): ключи и их значения должны совпасть 1-в-1 (в частности `thermEnv` в исходнике = `$thermEnv?->value`, где `$thermEnv` — уже `ThermalEnvironment`; здесь отдаём сырую строку query, которая для валидного значения эквивалентна `->value`; если шаблон где-то строго сравнивает с enum — верни `ThermalEnvironment::tryFrom($thermEnvRaw)?->value`).

- [ ] **Step 4: Запустить — зелёное**

Run: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/View/CoatingListViewFactoryTest.php`
Expected: PASS.

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Infrastructure/View/ app/tests/Unit/Coatings/Infrastructure/View/CoatingListViewFactoryTest.php
git commit -m "Пресеты и render-payload списка покрытий вынесены в CoatingRangePresets и CoatingListViewFactory"
```

---

### Task 4: Тонкий `Coating/ListAction` + функциональный safety-net

**Files:**
- Create: `app/tests/Functional/Coatings/Infrastructure/Controller/Coating/ListActionTest.php`
- Modify: `app/src/Coatings/Infrastructure/Controller/Coating/ListAction.php` (237 → ~40)

**Interfaces:**
- Consumes: `CoatingListRequestMapper` (Task 2), `CoatingListViewFactory` (Task 3), `QueryParams` (Task 1), `QueryBusInterface`, `GetPagedCoatingsQuery`, `GetPagedCoatingsQueryResult`, `AppException`.

- [ ] **Step 1: Написать функциональный тест (safety net)**

*Примечание:* скопируй структуру существующего `app/tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/ListActionTest.php` (base-класс, авторизация, метод `client`). Ниже — целевые проверки; подгони фикстуры/логин под тот образец.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\Coating;

// use <тот же базовый WebTestCase-класс, что и CoatingSystem/ListActionTest>

final class ListActionTest extends /* SameBaseAs CoatingSystem\ListActionTest */
{
    public function test_list_renders_ok(): void
    {
        $client = $this->authorizedClient(); // как в образце
        $client->request('GET', '/cabinet/coating/coating/list');

        self::assertResponseIsSuccessful();
    }

    public function test_list_with_facets_renders_ok(): void
    {
        $client = $this->authorizedClient();
        $client->request('GET', '/cabinet/coating/coating/list?minRecoat20From=2&baseValues[]=EP&sort=nonsense');

        self::assertResponseIsSuccessful();
    }

    public function test_inverted_range_shows_error_not_500(): void
    {
        $client = $this->authorizedClient();
        $client->request('GET', '/cabinet/coating/coating/list?appMinTempFrom=10&appMinTempTo=5');

        self::assertResponseIsSuccessful(); // ошибка рендерится в форме, не 500
    }

    public function test_partial_returns_cards_batch(): void
    {
        $client = $this->authorizedClient();
        $client->request('GET', '/cabinet/coating/coating/list?partial=1');

        self::assertResponseIsSuccessful();
    }
}
```

- [ ] **Step 2: Запустить против СТАРОГО экшена — baseline зелёный**

Run: `cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/Coating/ListActionTest.php`
Expected: PASS (старый толстый экшен уже даёт это поведение — тест фиксирует его как контракт до рефактора).

- [ ] **Step 3: Переписать экшен на оркестратор**

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQuery;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;
use App\Coatings\Infrastructure\Mapper\CoatingListRequestMapper;
use App\Coatings\Infrastructure\View\CoatingListViewFactory;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating/list', name: 'app_cabinet_coating_coating_list', methods: ['GET'])]
class ListAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CoatingListRequestMapper $requestMapper,
        private readonly CoatingListViewFactory $viewFactory,
        private readonly QueryParams $queryParams,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $error = null;
        try {
            $result = $this->queryBus->execute(
                new GetPagedCoatingsQuery($this->requestMapper->filterFromRequest($request)),
            );
        } catch (AppException $e) {
            $error = $e->getMessage();
            $result = new GetPagedCoatingsQueryResult([], Pager::fromPage(
                $this->queryParams->nullableInt($request, 'page'),
                $this->queryParams->nullableInt($request, 'limit'),
            ));
        }

        // Infinite scroll: AJAX-догрузка next-page отдаёт голый partial с карточками.
        if ($request->query->getBoolean('partial')) {
            return $this->render('admin/coating/coating/_coating_cards_batch.html.twig', [
                'coatings' => $result->coatings,
                'canEdit' => $this->isGranted('ROLE_ADMIN'),
                'selectedTagIdList' => $this->queryParams->stringCollection($request, 'tagIds')->getList(),
            ]);
        }

        return $this->render(
            'admin/coating/coating/index.html.twig',
            $this->viewFactory->build($request, $result, $error),
        );
    }
}
```

- [ ] **Step 4: Запустить — функциональный зелёный после рефактора**

Run: `cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/Coating/ListActionTest.php`
Expected: PASS. Дополнительно `wc -l` экшена ≈ 55 строк.

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Infrastructure/Controller/Coating/ListAction.php app/tests/Functional/Coatings/Infrastructure/Controller/Coating/ListActionTest.php
git commit -m "Coating/ListAction ужат с 237 строк до оркестратора: фильтр из мапера, payload из фабрики"
```

---

### Task 5: `CoatingSystemListRequestMapper` — query → CoatingSystemsFilter

**Files:**
- Create: `app/src/Coatings/Infrastructure/Mapper/CoatingSystemListRequestMapper.php`
- Test: `app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingSystemListRequestMapperTest.php`

**Interfaces:**
- Consumes: `QueryParams` (Task 1), `CoatingSystemsFilter`, `RangeFilter`, `SearchQuery`, `CoatingSystemSort`, `Substrate`, `ComplianceStandard`, `Pager`, `Uuid`.
- Produces: `CoatingSystemListRequestMapper::filterFromRequest(Request $request): CoatingSystemsFilter`. Инвертированный диапазон → `null` (поведение CoatingSystem-списка сохраняется — тихо роняем, без 500). `minApplicationTimeAt20` конвертируется из ЧАСОВ в минуты (× 60). `category`/`durability` осмысленны только при заданном `standard`. Дефолтный лимит страницы = 20.

- [ ] **Step 1: Написать падающий тест**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Mapper;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Coatings\Infrastructure\Mapper\CoatingSystemListRequestMapper;
use App\Shared\Infrastructure\Helper\QueryParams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CoatingSystemListRequestMapperTest extends TestCase
{
    private CoatingSystemListRequestMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new CoatingSystemListRequestMapper(new QueryParams());
    }

    public function test_empty_request_gives_defaults(): void
    {
        $filter = $this->mapper->filterFromRequest(Request::create('/', 'GET'));

        self::assertNull($filter->search);
        self::assertSame([], $filter->substrates);
        self::assertNull($filter->standard);
        self::assertNull($filter->category);
        self::assertSame(CoatingSystemSort::DEFAULT, $filter->sort);
    }

    public function test_substrates_are_enum_filtered(): void
    {
        $valid = Substrate::cases()[0]->value;
        $request = Request::create('/', 'GET', ['substrates' => [$valid, 'GARBAGE']]);

        $filter = $this->mapper->filterFromRequest($request);

        self::assertSame([Substrate::from($valid)], $filter->substrates);
    }

    public function test_category_ignored_without_standard(): void
    {
        $request = Request::create('/', 'GET', ['category' => 'C3', 'durability' => 'H']);

        $filter = $this->mapper->filterFromRequest($request);

        self::assertNull($filter->category);
        self::assertNull($filter->durability);
    }

    public function test_min_application_time_converted_to_minutes(): void
    {
        $request = Request::create('/', 'GET', [
            'minApplicationTimeAt20From' => '2', // часы → 120 минут
        ]);

        $filter = $this->mapper->filterFromRequest($request);

        self::assertNotNull($filter->minApplicationTimeAt20);
        self::assertSame(120, $filter->minApplicationTimeAt20->from);
    }

    public function test_inverted_range_is_dropped_to_null(): void
    {
        $request = Request::create('/', 'GET', [
            'applicationMinTempFrom' => '10',
            'applicationMinTempTo' => '5',
        ]);

        self::assertNull($this->mapper->filterFromRequest($request)->applicationMinTemp);
    }

    public function test_coating_ids_filtered_by_uuid(): void
    {
        $request = Request::create('/', 'GET', [
            'coatingIds' => ['11111111-1111-1111-1111-111111111111', 'not-a-uuid'],
        ]);

        $filter = $this->mapper->filterFromRequest($request);

        self::assertSame(['11111111-1111-1111-1111-111111111111'], $filter->coatingIds->getList());
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Mapper/CoatingSystemListRequestMapperTest.php`
Expected: FAIL — класс не найден.

- [ ] **Step 3: Реализовать мапер** (перенос блока 36–74 + `readRange` из `CoatingSystem/ListAction`)

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Mapper;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Query-параметры списка систем покрытий → CoatingSystemsFilter. Pure shape.
 * Инвертированный диапазон роняем в null (тихо, без ошибки — политика этого
 * списка, отличается от списка покрытий). Compliance-каскад: category/durability
 * осмысленны только при заданном standard.
 */
final class CoatingSystemListRequestMapper
{
    private const MINUTES_PER_HOUR = 60;
    private const DEFAULT_LIMIT = 20;

    public function __construct(private readonly QueryParams $query)
    {
    }

    public function filterFromRequest(Request $request): CoatingSystemsFilter
    {
        $q = trim((string) $request->query->get('q', ''));
        $standard = ComplianceStandard::tryFrom((string) $request->query->get('standard', ''));

        $substrates = array_values(array_filter(array_map(
            static fn (mixed $v): ?Substrate => Substrate::tryFrom((string) $v),
            $request->query->all('substrates'),
        )));

        return new CoatingSystemsFilter(
            search: '' !== $q ? SearchQuery::tryFromString($q) : null,
            substrates: $substrates,
            standard: $standard,
            category: null !== $standard ? ($request->query->get('category') ?: null) : null,
            durability: null !== $standard ? ($request->query->get('durability') ?: null) : null,
            tagIds: $this->query->stringCollection($request, 'tagIds'),
            coatingIds: $this->query->stringCollection(
                $request,
                'coatingIds',
                static fn (string $id): bool => Uuid::isValid($id),
            ),
            applicationMinTemp: $this->query->intRange($request, 'applicationMinTempFrom', 'applicationMinTempTo'),
            minApplicationTimeAt20: $this->query->intRange(
                $request,
                'minApplicationTimeAt20From',
                'minApplicationTimeAt20To',
                self::MINUTES_PER_HOUR,
            ),
            sort: CoatingSystemSort::tryFrom((string) $request->query->get('sort', '')) ?? CoatingSystemSort::DEFAULT,
            pager: Pager::fromPage(max(1, (int) $request->query->get('page', 1)), self::DEFAULT_LIMIT),
        );
    }
}
```

- [ ] **Step 4: Запустить — зелёное**

Run: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Mapper/CoatingSystemListRequestMapperTest.php`
Expected: PASS (6 тестов).

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Infrastructure/Mapper/CoatingSystemListRequestMapper.php app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingSystemListRequestMapperTest.php
git commit -m "CoatingSystemListRequestMapper: парсинг query систем ушёл из экшена, инверсия диапазона роняется в null"
```

---

### Task 6: `CoatingSystemListViewFactory`

**Files:**
- Create: `app/src/Coatings/Infrastructure/View/CoatingSystemListViewFactory.php`
- Test: `app/tests/Unit/Coatings/Infrastructure/View/CoatingSystemListViewFactoryTest.php`

**Interfaces:**
- Consumes: `QueryParams` (Task 1), `SearchCoatingSystemsQueryResult`, `CoatingSystemSort`, `Substrate`, `ComplianceStandard`, `Request`.
- Produces: `CoatingSystemListViewFactory::build(Request $request, SearchCoatingSystemsQueryResult $result): array<string, mixed>` — payload шаблона `cabinet/coating/coating_system/list.html.twig` (ключи 83–101 исходного экшена), включая `minApplicationTimeAt20Hours` (обратная конвертация минуты→часы) и echo-back.

- [ ] **Step 1: Написать падающий smoke-тест**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\View;

use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Coatings\Infrastructure\View\CoatingSystemListViewFactory;
use App\Shared\Infrastructure\Helper\QueryParams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CoatingSystemListViewFactoryTest extends TestCase
{
    public function test_build_payload_keys_and_hours_conversion(): void
    {
        $factory = new CoatingSystemListViewFactory(new QueryParams());
        $result = new SearchCoatingSystemsQueryResult([], 0);

        $payload = $factory->build(
            Request::create('/', 'GET', ['minApplicationTimeAt20From' => '2', 'q' => 'abc']),
            $result,
        );

        foreach ([
            'items', 'total', 'q', 'substrates', 'standard', 'category', 'durability',
            'tagIds', 'coatingIds', 'applicationMinTemp', 'minApplicationTimeAt20Hours',
            'sort', 'page', 'perPage', 'sortOptions', 'substrateOptions', 'standardOptions',
        ] as $key) {
            self::assertArrayHasKey($key, $payload);
        }
        self::assertSame('abc', $payload['q']);
        self::assertSame(['from' => 2, 'to' => 0], $payload['minApplicationTimeAt20Hours']);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/View/CoatingSystemListViewFactoryTest.php`
Expected: FAIL — класс не найден.

- [ ] **Step 3: Реализовать фабрику** (перенос сборки payload + `rangeToHours` из экшена)

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\View;

use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Shared\Domain\Repository\RangeFilter;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Full render-payload списка систем покрытий. Echo-back значений формы + опции
 * селектов + обратная конвертация минуты→часы для слайдера времени нанесения.
 */
final class CoatingSystemListViewFactory
{
    private const MINUTES_PER_HOUR = 60;
    private const DEFAULT_LIMIT = 20;

    public function __construct(private readonly QueryParams $query)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request, SearchCoatingSystemsQueryResult $result): array
    {
        $standard = ComplianceStandard::tryFrom((string) $request->query->get('standard', ''));
        $category = $request->query->get('category') ?: null;
        $durability = $request->query->get('durability') ?: null;

        $substrates = array_values(array_filter(array_map(
            static fn (mixed $v): ?Substrate => Substrate::tryFrom((string) $v),
            $request->query->all('substrates'),
        )));

        $tagIds = $this->query->stringCollection($request, 'tagIds');
        $coatingIds = $this->query->stringCollection(
            $request,
            'coatingIds',
            static fn (string $id): bool => Uuid::isValid($id),
        );

        $applicationMinTemp = $this->query->intRange($request, 'applicationMinTempFrom', 'applicationMinTempTo');
        $minAppTime = $this->query->intRange($request, 'minApplicationTimeAt20From', 'minApplicationTimeAt20To', self::MINUTES_PER_HOUR);

        return [
            'items' => $result->items,
            'total' => $result->total,
            'q' => trim((string) $request->query->get('q', '')),
            'substrates' => $substrates,
            'standard' => $standard,
            'category' => null !== $standard ? $category : null,
            'durability' => null !== $standard ? $durability : null,
            'tagIds' => $tagIds->getList(),
            'coatingIds' => $coatingIds->getList(),
            'applicationMinTemp' => $applicationMinTemp,
            'minApplicationTimeAt20Hours' => $this->rangeToHours($minAppTime),
            'sort' => CoatingSystemSort::tryFrom((string) $request->query->get('sort', '')) ?? CoatingSystemSort::DEFAULT,
            'page' => max(1, (int) $request->query->get('page', 1)),
            'perPage' => self::DEFAULT_LIMIT,
            'sortOptions' => CoatingSystemSort::cases(),
            'substrateOptions' => Substrate::cases(),
            'standardOptions' => ComplianceStandard::cases(),
        ];
    }

    /** @return array{from: int, to: int}|null */
    private function rangeToHours(?RangeFilter $range): ?array
    {
        if (null === $range) {
            return null;
        }

        return [
            'from' => null !== $range->from ? (int) round($range->from / self::MINUTES_PER_HOUR) : 0,
            'to' => null !== $range->to ? (int) round($range->to / self::MINUTES_PER_HOUR) : 0,
        ];
    }
}
```

*Примечание для исполнителя:* построение RangeFilter из query — общий `QueryParams::intRange` (Task 1), им пользуются и мапер (Task 5), и эта фабрика. Дубля нет. `rangeToHours` остаётся здесь — это чисто view-конвертация минуты→часы для слайдера, у мапера её нет.

- [ ] **Step 4: Запустить — зелёное**

Run: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/View/CoatingSystemListViewFactoryTest.php`
Expected: PASS.

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Infrastructure/View/CoatingSystemListViewFactory.php app/tests/Unit/Coatings/Infrastructure/View/CoatingSystemListViewFactoryTest.php
git commit -m "Render-payload списка систем покрытий вынесен в CoatingSystemListViewFactory"
```

---

### Task 7: Тонкий `CoatingSystem/ListAction`

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Controller/CoatingSystem/ListAction.php` (133 → ~35)

**Interfaces:**
- Consumes: `CoatingSystemListRequestMapper` (Task 5), `CoatingSystemListViewFactory` (Task 6), `QueryBusInterface`, `SearchCoatingSystemsQuery`, `SearchCoatingSystemsQueryResult`.
- Safety net: существующий `app/tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/ListActionTest.php`.

- [ ] **Step 1: Baseline — существующий функциональный зелёный**

Run: `cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/ListActionTest.php`
Expected: PASS (фиксируем контракт до рефактора).

- [ ] **Step 2: Переписать экшен на оркестратор**

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Coatings\Infrastructure\Mapper\CoatingSystemListRequestMapper;
use App\Coatings\Infrastructure\View\CoatingSystemListViewFactory;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coating/coating-system/list',
    name: 'app_cabinet_coating_system_list',
    methods: ['GET'],
)]
final class ListAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CoatingSystemListRequestMapper $requestMapper,
        private readonly CoatingSystemListViewFactory $viewFactory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var SearchCoatingSystemsQueryResult $result */
        $result = $this->queryBus->execute(
            new SearchCoatingSystemsQuery($this->requestMapper->filterFromRequest($request)),
        );

        $template = $request->query->getBoolean('partial')
            ? 'cabinet/coating/coating_system/_list_cards.html.twig'
            : 'cabinet/coating/coating_system/list.html.twig';

        return $this->render($template, $this->viewFactory->build($request, $result));
    }
}
```

- [ ] **Step 3: Запустить — функциональный зелёный после рефактора**

Run: `cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/ListActionTest.php`
Expected: PASS. `wc -l` экшена ≈ 40 строк.

- [ ] **Step 4: Коммит**

```bash
git add app/src/Coatings/Infrastructure/Controller/CoatingSystem/ListAction.php
git commit -m "CoatingSystem/ListAction стал оркестратором: фильтр из мапера, payload из фабрики"
```

---

### Task 8: `CoatingSystemMapper::layersFromInput` — pure shape слоёв

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Mapper/CoatingSystemMapper.php`
- Test: `app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingSystemMapperTest.php` (+ новый метод)

**Interfaces:**
- Produces: `CoatingSystemMapper::layersFromInput(array $raw): array` → `list<array{coatingId: string, dft: int}>`. Pure shape: приводит `coatingId` к строке, `dft` к int, **отбрасывает** строки без непустого `coatingId` или не-массивы. Никаких бизнес-throw'ов (валидность uuid и `dft > 0` — забота домена `CoatingSystemLayer`).
- Рефактор: `buildCommandFromInputData` (create-ветка) собирает `initialLayers` через `layersFromInput`.

- [ ] **Step 1: Добавить падающий тест-метод**

```php
public function test_layers_from_input_is_pure_shape(): void
{
    $raw = [
        ['coatingId' => 'uuid-1', 'dft' => '60'],
        ['coatingId' => '', 'dft' => '10'],       // без coatingId — отбрасываем
        'garbage-not-array',                       // не массив — отбрасываем
        ['coatingId' => 'uuid-2', 'dft' => 100],
    ];

    $layers = $this->mapper->layersFromInput($raw);

    self::assertSame(
        [
            ['coatingId' => 'uuid-1', 'dft' => 60],
            ['coatingId' => 'uuid-2', 'dft' => 100],
        ],
        $layers,
    );
}

public function test_layers_from_input_does_not_throw_on_nonpositive_dft(): void
{
    // dft <= 0 — НЕ дело мапера: инвариант живёт в CoatingSystemLayer.
    $layers = $this->mapper->layersFromInput([['coatingId' => 'uuid-1', 'dft' => '0']]);

    self::assertSame([['coatingId' => 'uuid-1', 'dft' => 0]], $layers);
}
```

- [ ] **Step 2: Запустить — падает**

Run: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Mapper/CoatingSystemMapperTest.php --filter layers_from_input`
Expected: FAIL — метод `layersFromInput` не существует.

- [ ] **Step 3: Реализовать `layersFromInput` и переиспользовать в create-ветке**

Добавить метод в `CoatingSystemMapper`:

```php
/**
 * Плоский список слоёв формы → нормализованный shape. Только форма данных:
 * кривые строки отбрасываются, dft приводится к int. Бизнес-правила
 * (валидность покрытия, положительность толщины) проверяет домен.
 *
 * @param array<mixed> $raw
 *
 * @return list<array{coatingId: string, dft: int}>
 */
public function layersFromInput(array $raw): array
{
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $coatingId = (string) ($item['coatingId'] ?? '');
        if ('' === $coatingId) {
            continue;
        }
        $out[] = ['coatingId' => $coatingId, 'dft' => (int) ($item['dft'] ?? 0)];
    }

    return $out;
}
```

Заменить inline-сборку `$layers` в `buildCommandFromInputData` (create-ветка, строки 37–43) на:

```php
if (null === $systemId) {
    return new CreateCoatingSystemCommand(
        title: (string) ($input['title'] ?? ''),
        description: (string) ($input['description'] ?? ''),
        substrate: $substrate,
        surfaceTreatmentId: $surfaceTreatmentId,
        initialLayers: $this->layersFromInput((array) ($input['layers'] ?? [])),
        tagIds: $tagIds,
    );
}
```

- [ ] **Step 4: Запустить — весь mapper-тест зелёный** (включая существующий round-trip и create-тест)

Run: `cd app && vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Mapper/CoatingSystemMapperTest.php`
Expected: PASS. (Проверь, что `test_build_create_command_from_input` по-прежнему даёт `initialLayers[0] = ['coatingId'=>'uuid-1','dft'=>60]`.)

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Infrastructure/Mapper/CoatingSystemMapper.php app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingSystemMapperTest.php
git commit -m "CoatingSystemMapper.layersFromInput: shape слоёв в одном месте, без бизнес-throw'ов"
```

---

### Task 9: `CoatingSystemFormRehydrator` — гидрация тайпхедов после POST-ошибки

**Files:**
- Create: `app/src/Coatings/Application/Service/CoatingSystemFormRehydrator.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Service/CoatingSystemFormRehydratorTest.php`

**Interfaces:**
- Consumes: `QueryBusInterface`, `FindSurfaceTreatmentByIdQuery`, `CoatingRepositoryInterface`, `Uuid`.
- Produces: `CoatingSystemFormRehydrator::enrichInputDataWithTitles(array $inputData): array` → тот же inputData + `surfaceTreatmentTitle` (по `surfaceTreatmentId`) + `coatingTitlesById` (по `layers[].coatingId`). Объединяет разъехавшиеся `enrichInputDataWithTitles` (Add) и `enrichWithTreatmentTitle` (Update): Add-версия — суперсет (treatment + coatings), Update получает то же самое (coatings-титулы ему тоже полезны для слоёв). Формат `coatingTitlesById`: `[<id> => "<title> (<base>, <min>–<max> мкм)"]`.

- [ ] **Step 1: Написать падающий функциональный тест**

*Примечание:* скопируй загрузку фикстур/базовый класс из `app/tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/AddActionTest.php`. Нужны реальные покрытие и surface-treatment в БД (Doctrine не любит моки — CLAUDE.md).

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Service;

use App\Coatings\Application\Service\CoatingSystemFormRehydrator;
// use <тот же базовый KernelTestCase-класс и фикстуры, что в CoatingSystem/AddActionTest>

final class CoatingSystemFormRehydratorTest extends /* SameBaseAsAddActionTest */
{
    public function test_enriches_treatment_and_coating_titles(): void
    {
        // Arrange: создать surface-treatment (ST) и coating (C) в БД через фикстуры образца.
        // $treatmentId = ...; $coatingId = ...; $coatingTitle = ...;
        $rehydrator = self::getContainer()->get(CoatingSystemFormRehydrator::class);

        $input = [
            'surfaceTreatmentId' => $treatmentId,
            'layers' => [['coatingId' => $coatingId, 'dft' => 60]],
        ];

        $out = $rehydrator->enrichInputDataWithTitles($input);

        self::assertArrayHasKey('surfaceTreatmentTitle', $out);
        self::assertNotSame('', $out['surfaceTreatmentTitle']);
        self::assertArrayHasKey('coatingTitlesById', $out);
        self::assertArrayHasKey($coatingId, $out['coatingTitlesById']);
        self::assertStringContainsString($coatingTitle, $out['coatingTitlesById'][$coatingId]);
    }

    public function test_no_ids_leaves_input_without_lookups(): void
    {
        $rehydrator = self::getContainer()->get(CoatingSystemFormRehydrator::class);

        $out = $rehydrator->enrichInputDataWithTitles(['layers' => []]);

        self::assertSame([], $out['coatingTitlesById']);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Service/CoatingSystemFormRehydratorTest.php`
Expected: FAIL — класс/сервис не найден.

- [ ] **Step 3: Реализовать сервис** (слияние обеих `enrich*` из Add/Update)

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\Service;

use App\Coatings\Application\UseCase\Query\FindSurfaceTreatmentById\FindSurfaceTreatmentByIdQuery;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * После POST-ошибки формы системы покрытий подтягивает человекочитаемые
 * заголовки для async-typeahead (подготовка поверхности + покрытия в слоях),
 * чтобы форма перерисовалась с восстановленными preselected-тегами.
 *
 * Не мапер: делает DB/queryBus-лукапы. Один общий инструмент для Add и Update
 * вместо двух разъехавшихся enrich*-методов в контроллерах.
 */
final readonly class CoatingSystemFormRehydrator
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CoatingRepositoryInterface $coatingRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $inputData
     *
     * @return array<string, mixed>
     */
    public function enrichInputDataWithTitles(array $inputData): array
    {
        $inputData['surfaceTreatmentTitle'] = $this->treatmentTitle($inputData['surfaceTreatmentId'] ?? null)
            ?? ($inputData['surfaceTreatmentTitle'] ?? '');
        $inputData['coatingTitlesById'] = $this->coatingTitles($inputData['layers'] ?? []);

        return $inputData;
    }

    private function treatmentTitle(mixed $treatmentId): ?string
    {
        if (!is_string($treatmentId) || '' === $treatmentId || !Uuid::isValid($treatmentId)) {
            return null;
        }
        $dto = $this->queryBus->execute(new FindSurfaceTreatmentByIdQuery($treatmentId));

        return null !== $dto ? $dto->title : null;
    }

    /**
     * @param mixed $layers
     *
     * @return array<string, string>
     */
    private function coatingTitles(mixed $layers): array
    {
        $ids = [];
        foreach ((array) $layers as $layer) {
            $cid = is_array($layer) ? ($layer['coatingId'] ?? null) : null;
            if (is_string($cid) && Uuid::isValid($cid)) {
                $ids[] = $cid;
            }
        }
        if ([] === $ids) {
            return [];
        }

        $titles = [];
        foreach ($this->coatingRepository->findByIds($ids) as $coating) {
            $dft = $coating->getDftRange();
            $titles[$coating->getId()] = sprintf(
                '%s (%s, %d–%d мкм)',
                $coating->getTitle(),
                $coating->getBase()->value,
                (int) $dft->range->getMin(),
                (int) $dft->range->getMax(),
            );
        }

        return $titles;
    }
}
```

*Примечание для исполнителя:* сверь сигнатуры `CoatingRepositoryInterface::findByIds`, `$coating->getId()/getTitle()/getBase()/getDftRange()` с исходным `CoatingSystem/AddAction::enrichInputDataWithTitles` (строки 86–123) — код перенесён оттуда 1-в-1.

- [ ] **Step 4: Запустить — зелёное**

Run: `cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Service/CoatingSystemFormRehydratorTest.php`
Expected: PASS.

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Application/Service/CoatingSystemFormRehydrator.php app/tests/Functional/Coatings/Infrastructure/Service/CoatingSystemFormRehydratorTest.php
git commit -m "CoatingSystemFormRehydrator: одна гидрация тайпхедов вместо двух разъехавшихся enrich-методов"
```

---

### Task 10: Тонкие `CoatingSystem/AddAction` и `UpdateAction`

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Controller/CoatingSystem/AddAction.php`
- Modify: `app/src/Coatings/Infrastructure/Controller/CoatingSystem/UpdateAction.php`

**Interfaces:**
- Consumes: `CoatingSystemFormRehydrator` (Task 9), `CoatingSystemMapper::layersFromInput` (Task 8), `ReplaceLayersCommand`.
- Safety net: существующие `AddActionTest`, `UpdateActionTest`.
- Removals: `AddAction::enrichInputDataWithTitles`, `UpdateAction::enrichWithTreatmentTitle`, `UpdateAction::normalizeLayersInput` (и его бизнес-throw'ы) — удалить полностью вместе с неиспользуемыми зависимостями (`CoatingRepositoryInterface` в Add, `Uuid`-импорт где больше не нужен).

- [ ] **Step 1: Baseline — существующие функциональные зелёные**

Run: `cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/AddActionTest.php tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/UpdateActionTest.php`
Expected: PASS.

- [ ] **Step 2: Переписать `UpdateAction`** — `layersFromInput` вместо `normalizeLayersInput`, `rehydrator` вместо `enrichWithTreatmentTitle`

Ключевые изменения в `__invoke`:

```php
$this->commandBus->execute(new ReplaceLayersCommand(
    $id,
    $this->mapper->layersFromInput($layersInput),
));
```

и в catch-ветке:

```php
} catch (\Exception $e) {
    $error = $e->getMessage();
    $inputData = $this->rehydrator->enrichInputDataWithTitles($inputData);
    $rawTagIds = array_map(
        static fn (string $tagId) => ['id' => $tagId],
        (array) ($inputData['tagIds'] ?? []),
    );
    $freshDto = $this->queryBus->execute(new FindCoatingSystemByIdQuery($id)) ?? $dto;

    return $this->render('cabinet/coating/coating_system/form.html.twig', [
        'error' => $error,
        'inputData' => $inputData,
        'systemId' => $id,
        'substrates' => Substrate::cases(),
        'existingTagsJson' => $this->tagsHydrator->hydrateAsJson($rawTagIds),
        'layersDto' => $freshDto,
    ]);
}
```

Конструктор: заменить прямые зависимости на `CoatingSystemFormRehydrator $rehydrator` (вместо использования `FindSurfaceTreatmentByIdQuery` внутри экшена). Удалить приватные методы `normalizeLayersInput` и `enrichWithTreatmentTitle` целиком. Удалить импорты, ставшие мёртвыми (`FindSurfaceTreatmentByIdQuery`, `Uuid`, `AppException` — если больше не кидается/ловится в этих методах; `\Exception` в catch остаётся).

- [ ] **Step 3: Переписать `AddAction`** — `rehydrator` вместо `enrichInputDataWithTitles`

В catch-ветке заменить `$inputData = $this->enrichInputDataWithTitles($inputData);` на `$inputData = $this->rehydrator->enrichInputDataWithTitles($inputData);`. Удалить приватный метод `enrichInputDataWithTitles` и зависимости `CoatingRepositoryInterface`, `QueryBusInterface` (если не используются в остатке), импорты `FindSurfaceTreatmentByIdQuery`, `Uuid`. Добавить в конструктор `CoatingSystemFormRehydrator $rehydrator`.

*Примечание для исполнителя:* проверь, остался ли `QueryBusInterface` нужен в Add (в исходнике он использовался только внутри `enrichInputDataWithTitles`). Если да — убрать из конструктора. В Update `queryBus` остаётся (нужен для `FindCoatingSystemByIdQuery`).

- [ ] **Step 4: Запустить — функциональные зелёные после рефактора**

Run: `cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/AddActionTest.php tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/UpdateActionTest.php`
Expected: PASS. В контроллерах не осталось `throw new AppException(...)` про доменные правила (grep — ноль).

- [ ] **Step 5: Коммит**

```bash
git add app/src/Coatings/Infrastructure/Controller/CoatingSystem/AddAction.php app/src/Coatings/Infrastructure/Controller/CoatingSystem/UpdateAction.php
git commit -m "CoatingSystem Add/Update: слои через мапер, гидрация через rehydrator, бизнес-throw'ы убраны из контроллеров"
```

---

### Task 11: Аудит `SurfaceTreatment/*` и `Tag/*`

**Files (candidates):**
- `app/src/Coatings/Infrastructure/Controller/SurfaceTreatment/{Add,Update,List,Suggest,Remove,CreateInline}Action.php`
- `app/src/Coatings/Infrastructure/Controller/Tag/{CreateGeneralTag,List,SuggestTags}Action.php`

**Задача:** применить те же три критерия — трогаем экшен ТОЛЬКО если в нём есть (а) inline-парсинг query, дублирующий уже введённые идиомы, (б) бизнес-`throw` в контроллере, (в) дублированный across-Add/Update код гидрации/re-render. Если ни одного — no-op, зафиксировать в заметке. Не рефакторить ради ровного счёта строк (YAGNI).

- [ ] **Step 1: Прочитать все 9 экшенов и классифицировать**

Run: `cd app && grep -n 'throw new AppException\|->query->get\|->query->all\|private function' src/Coatings/Infrastructure/Controller/SurfaceTreatment/*.php src/Coatings/Infrastructure/Controller/Tag/*.php`

Для каждого экшена отметить: есть ли (а)/(б)/(в). Составить список «трогаем / не трогаем» с обоснованием.

- [ ] **Step 2: Точечно причесать только помеченные**

Для каждого помеченного — минимальная правка по образцу уже сделанного (парсинг → `QueryParams`; бизнес-`throw` → в узкий VO/агрегат домена; дубль → в существующий rehydrator/mapper-метод). Каждая правка сопровождается прогоном соответствующего существующего функционального теста (`SurfaceTreatment/*Test`, `Tag/*Test`) — до и после.

*Правило развилки:* если правка требует переноса бизнес-правила в домен и неочевидно, в какой VO/агрегат — ОСТАНОВИТЬСЯ и спросить пользователя (эксперт проекта), а не гадать (CLAUDE.md: «строго по плану, расхождение — озвучить»).

- [ ] **Step 3: Прогнать функциональные по затронутым**

Run: `cd app && vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/SurfaceTreatment tests/Functional/Coatings/Infrastructure/Controller/Tag`
Expected: PASS.

- [ ] **Step 4: Коммит (если были правки)**

```bash
git add app/src/Coatings/Infrastructure/Controller/SurfaceTreatment app/src/Coatings/Infrastructure/Controller/Tag
git commit -m "SurfaceTreatment/Tag экшены причёсаны под общие идиомы там, где были те же антипаттерны"
```

Если правок не было — коммит пропустить, зафиксировать вывод «антипаттернов нет» в PR-описании.

---

### Task 12: Финальная верификация

**Files:** нет новых.

- [ ] **Step 1: Полный прогон Coatings-тестов**

Run: `cd app && vendor/bin/phpunit tests/Unit/Coatings tests/Functional/Coatings`
Expected: PASS, ноль ошибок/скипов по существу.

- [ ] **Step 2: Статик-анализ и стиль (CI-гейты)**

Run: `cd app && vendor/bin/phpunit && vendor/bin/phpstan analyse && vendor/bin/php-cs-fixer fix --dry-run --diff`
Expected: phpstan чист, cs-fixer без диффов. Если cs-fixer ругается — `vendor/bin/php-cs-fixer fix`, пере-прогнать тесты, до-коммитить.

*Примечание:* точные команды phpstan/cs-fixer сверь с `app/composer.json` scripts или `.github/workflows` — используй те, что гоняет CI.

- [ ] **Step 3: Проверить целевые метрики**

Run: `cd app && wc -l src/Coatings/Infrastructure/Controller/Coating/ListAction.php src/Coatings/Infrastructure/Controller/CoatingSystem/ListAction.php && grep -rn 'throw new AppException' src/Coatings/Infrastructure/Controller/`
Expected: `Coating/ListAction` ≲ 55 строк, `CoatingSystem/ListAction` ≲ 40, ноль бизнес-`throw` в контроллерах Coatings (404-not-found в других экшенах — не бизнес-правило, допустимо; оценить глазами).

- [ ] **Step 4: Проверить отсутствие мёртвого кода**

Run: `cd app && grep -rn 'normalizeLayersInput\|enrichWithTreatmentTitle\|enrichInputDataWithTitles' src/`
Expected: единственное вхождение `enrichInputDataWithTitles` — публичный метод `CoatingSystemFormRehydrator`; `normalizeLayersInput`/`enrichWithTreatmentTitle` — ноль вхождений.

- [ ] **Step 5: Финальный коммит (если что-то доправлялось на Step 2)**

```bash
git add -A
git commit -m "Причёсывание Coatings-экшенов: зелёный CI, тонкие оркестраторы, единые идиомы"
```

---

## Self-Review (выполнено при написании плана)

**Spec coverage:**
- «Query → Filter в мапере» → Tasks 2, 5. Есть.
- «Общий хелпер query, унификация идиом» → Task 1. Есть.
- «Пресеты из контроллера» → Task 3 (`CoatingRangePresets`). Есть.
- «Render-payload → view-factory» → Tasks 3, 6. Есть.
- «Слои — shape в мапер, инвариант в домене» → Task 8 (+ подтверждено: `PositiveNumberRange` гарантирует min>0, домен не трогаем). Есть.
- «Enrich → один сервис» → Task 9, применён в Task 10. Есть.
- «Тонкие экшены» → Tasks 4, 7, 10. Есть.
- «Аудит SurfaceTreatment/Tag» → Task 11. Есть.
- «Тесты + зелёный CI» → каждый Task + Task 12. Есть.

**Placeholder scan:** конкретные «сверь сигнатуру» — это указания исполнителю проверить реальные имена методов образца (TagDTOTransformer-конструктор, базовые классы функциональных тестов), а не пропуски логики; код везде приведён целиком.

**Type consistency:** `filterFromRequest(Request): CoatingsFilter|CoatingSystemsFilter`, `build(Request, Result[, ?error]): array`, `layersFromInput(array): list<array{coatingId:string,dft:int}>`, `enrichInputDataWithTitles(array): array` — имена совпадают между определением и потреблением во всех тасках.

## Открытые развилки для исполнителя (эскалировать пользователю, не решать молча)

1. **Task 11:** если у SurfaceTreatment/Tag найдётся бизнес-`throw`, чьё «правильное» место в домене неочевидно — спросить.
2. **(Разрешено на пред-полёте)** Дубль `range()` мапер↔фабрика унифицирован в `QueryParams::intRange` — решение пользователя. `rangeToHours` осталось во view-factory (view-only).
