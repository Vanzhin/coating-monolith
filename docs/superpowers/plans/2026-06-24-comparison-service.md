# Comparison Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a type-agnostic `ObjectComparator` service in `Shared/Application/Comparison/` and wire up the first consumer — a side-by-side comparison page for 2–4 coatings with tray-based selection and field-filter sidebar.

**Architecture:** Two layers — (1) a small `Shared` service that takes a list of property paths + variadic objects and returns rows with `isDifferent` flags via Symfony PropertyAccess; (2) a per-type Coating layer (controller, query, Twig template, two Stimulus controllers) that knows which fields matter, how to format them, and how the user selects coatings.

**Tech Stack:** PHP 8.x readonly classes, Symfony PropertyAccess, PHPUnit 9.6, Doctrine ORM, Symfony Form/Validator, Twig 3, Stimulus 3 (auto-discovered via stimulus-bridge), Bootstrap 5, Webpack Encore.

## Global Constraints

- All commands run from `app/` (project root for PHP/Symfony/Yarn). `cd app` once at session start.
- Tests: `vendor/bin/phpunit <path>` from `app/`. No global PHPUnit.
- Asset rebuild after any JS/Twig change: `yarn dev` from `app/`. Hard-reload browser (`Cmd+Shift+R`) after rebuild.
- Domain rules (CLAUDE.md): bizness invariants live in domain VOs; mappers and infrastructure stay pure (no business validation). DTOs are bare data containers.
- AppException = single channel of business errors → controller catch → `<div class="alert alert-danger">`. Use `App\Shared\Infrastructure\Exception\AppException`.
- **Do NOT run `git add` or `git commit`** — the user manages git manually. Each task ends with a "Commit checkpoint" note describing what should be committed; the user runs git themselves.

---

### Task 1: Core comparison service (Shared)

**Files:**
- Create: `app/src/Shared/Application/Comparison/ComparisonConfig.php`
- Create: `app/src/Shared/Application/Comparison/ComparisonRow.php`
- Create: `app/src/Shared/Application/Comparison/ComparisonResult.php`
- Create: `app/src/Shared/Application/Comparison/ObjectComparator.php`
- Test: `app/tests/Unit/Shared/Application/Comparison/ObjectComparatorTest.php`

**Interfaces:**
- Consumes: `Symfony\Component\PropertyAccess\PropertyAccessorInterface` (auto-wired by Symfony).
- Produces:
  - `ComparisonConfig(public array $fields)` — `list<string>` PropertyAccess paths.
  - `ComparisonRow(public string $field, public array $values, public bool $isDifferent)`.
  - `ComparisonResult(public array $rows)` — `list<ComparisonRow>`.
  - `ObjectComparator::compare(ComparisonConfig $config, object ...$objects): ComparisonResult`.

- [ ] **Step 1: Write the failing unit test**

Create `app/tests/Unit/Shared/Application/Comparison/ObjectComparatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Comparison;

use App\Shared\Application\Comparison\ComparisonConfig;
use App\Shared\Application\Comparison\ObjectComparator;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

final class ObjectComparatorTest extends TestCase
{
    private function comparator(): ObjectComparator
    {
        return new ObjectComparator(PropertyAccess::createPropertyAccessor());
    }

    public function testThrowsWhenFewerThanTwoObjects(): void
    {
        $this->expectException(AppException::class);
        $this->comparator()->compare(new ComparisonConfig(['x']), new \stdClass());
    }

    public function testThrowsWhenObjectsAreDifferentClasses(): void
    {
        $a = new class { public int $x = 1; };
        $b = new class { public int $x = 1; };
        $this->expectException(AppException::class);
        $this->comparator()->compare(new ComparisonConfig(['x']), $a, $b);
    }

    public function testScalarFieldsEqualMarkedNotDifferent(): void
    {
        $a = new class { public int $x = 1; public string $s = 'a'; };
        $b = clone $a;
        $result = $this->comparator()->compare(new ComparisonConfig(['x', 's']), $a, $b);
        $this->assertCount(2, $result->rows);
        $this->assertFalse($result->rows[0]->isDifferent);
        $this->assertFalse($result->rows[1]->isDifferent);
        $this->assertSame([1, 1], $result->rows[0]->values);
    }

    public function testScalarFieldsDifferMarkedDifferent(): void
    {
        $a = new class { public int $x = 1; };
        $b = new class { public int $x = 2; };
        $result = $this->comparator()->compare(new ComparisonConfig(['x']), $a, $b);
        $this->assertTrue($result->rows[0]->isDifferent);
        $this->assertSame([1, 2], $result->rows[0]->values);
    }

    public function testStructurallyEqualValueObjectsMarkedNotDifferent(): void
    {
        $vo1 = new readonly class(10, 20) { public function __construct(public int $min, public int $max) {} };
        $vo2 = new $vo1(10, 20);
        $owner1 = new class($vo1) { public function __construct(public object $range) {} };
        $owner2 = new $owner1($vo2);
        $result = $this->comparator()->compare(new ComparisonConfig(['range']), $owner1, $owner2);
        $this->assertFalse($result->rows[0]->isDifferent, 'SORT_REGULAR should deep-compare VO by props');
    }

    public function testNestedPropertyPath(): void
    {
        $inner1 = new class { public int $tds = 100; };
        $inner2 = new class { public int $tds = 100; };
        $a = new class($inner1) { public function __construct(public object $dft) {} };
        $b = new class($inner2) { public function __construct(public object $dft) {} };
        $result = $this->comparator()->compare(new ComparisonConfig(['dft.tds']), $a, $b);
        $this->assertSame('dft.tds', $result->rows[0]->field);
        $this->assertSame([100, 100], $result->rows[0]->values);
        $this->assertFalse($result->rows[0]->isDifferent);
    }

    public function testThreeObjectsAllEqual(): void
    {
        $make = fn(int $v) => new class($v) { public function __construct(public int $x) {} };
        $result = $this->comparator()->compare(new ComparisonConfig(['x']), $make(5), $make(5), $make(5));
        $this->assertFalse($result->rows[0]->isDifferent);
    }

    public function testThreeObjectsOneDiffers(): void
    {
        $make = fn(int $v) => new class($v) { public function __construct(public int $x) {} };
        $result = $this->comparator()->compare(new ComparisonConfig(['x']), $make(5), $make(5), $make(7));
        $this->assertTrue($result->rows[0]->isDifferent);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```bash
vendor/bin/phpunit tests/Unit/Shared/Application/Comparison/ObjectComparatorTest.php
```

Expected: Errors — `class "App\Shared\Application\Comparison\..." not found`.

- [ ] **Step 3: Create the four classes**

Create `app/src/Shared/Application/Comparison/ComparisonConfig.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Application\Comparison;

final readonly class ComparisonConfig
{
    /** @param list<string> $fields пути PropertyAccess: 'title', 'dftRange.tdsDft' */
    public function __construct(public array $fields)
    {
    }
}
```

Create `app/src/Shared/Application/Comparison/ComparisonRow.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Application\Comparison;

final readonly class ComparisonRow
{
    /** @param list<mixed> $values значения по объектам, в порядке входа */
    public function __construct(
        public string $field,
        public array $values,
        public bool $isDifferent,
    ) {
    }
}
```

Create `app/src/Shared/Application/Comparison/ComparisonResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Application\Comparison;

final readonly class ComparisonResult
{
    /** @param list<ComparisonRow> $rows */
    public function __construct(public array $rows)
    {
    }
}
```

Create `app/src/Shared/Application/Comparison/ObjectComparator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Application\Comparison;

use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Type-agnostic сервис сравнения. Достаёт значения по PropertyAccess-путям из конфига
 * и для каждого поля возвращает строку с флагом «отличаются ли значения».
 *
 * Подписи, единицы, форматирование — забота вызывающего слоя (controller/template).
 */
final readonly class ObjectComparator
{
    public function __construct(private PropertyAccessorInterface $propertyAccessor)
    {
    }

    public function compare(ComparisonConfig $config, object ...$objects): ComparisonResult
    {
        if (count($objects) < 2) {
            throw new AppException('Нужно минимум 2 объекта для сравнения.');
        }
        $class = $objects[0]::class;
        foreach ($objects as $obj) {
            if ($obj::class !== $class) {
                throw new AppException(sprintf(
                    'Все объекты должны быть одного класса; получены %s и %s.',
                    $class,
                    $obj::class,
                ));
            }
        }

        $rows = [];
        foreach ($config->fields as $field) {
            $values = array_map(
                fn(object $obj) => $this->propertyAccessor->getValue($obj, $field),
                $objects,
            );
            // SORT_REGULAR глубоко сравнивает VO/массивы по значениям свойств.
            $isDifferent = count(array_unique($values, SORT_REGULAR)) > 1;
            $rows[] = new ComparisonRow($field, $values, $isDifferent);
        }

        return new ComparisonResult($rows);
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

```bash
vendor/bin/phpunit tests/Unit/Shared/Application/Comparison/ObjectComparatorTest.php
```

Expected: `OK (8 tests, X assertions)`.

- [ ] **Step 5: Run full unit suite to verify no regression**

```bash
vendor/bin/phpunit tests/Unit
```

Expected: all green, count increased by 8.

- [ ] **Step 6: Commit checkpoint (user)**

User commits: 4 new Shared/Application/Comparison classes + new unit test. Suggested message: `feat: add type-agnostic ObjectComparator service`.

---

### Task 2: GetCoatingsByIdsQuery (load multiple coatings)

**Files:**
- Create: `app/src/Coatings/Application/UseCase/Query/GetCoatingsByIds/GetCoatingsByIdsQuery.php`
- Create: `app/src/Coatings/Application/UseCase/Query/GetCoatingsByIds/GetCoatingsByIdsQueryResult.php`
- Create: `app/src/Coatings/Application/UseCase/Query/GetCoatingsByIds/GetCoatingsByIdsQueryHandler.php`
- Modify: `app/src/Coatings/Domain/Repository/CoatingRepositoryInterface.php` (add `findByIds`)
- Modify: `app/src/Coatings/Infrastructure/Repository/CoatingRepository.php` (implement `findByIds`)

**Interfaces:**
- Consumes: `CoatingRepositoryInterface`, `CoatingDTOTransformer`.
- Produces:
  - `GetCoatingsByIdsQuery(public array $ids)` — `list<string>` UUIDs.
  - `GetCoatingsByIdsQueryResult(public array $coatings)` — `list<CoatingDTO>`, в том же порядке что и `$ids` (отсутствующие пропущены).
  - `CoatingRepositoryInterface::findByIds(array $ids): array` — `list<Coating>` в порядке `$ids`.

- [ ] **Step 1: Inspect existing single-query pattern**

Read `app/src/Coatings/Application/UseCase/Query/GetCoating/GetCoatingQueryHandler.php` to match the existing style.

- [ ] **Step 2: Add `findByIds` to repo interface**

Edit `app/src/Coatings/Domain/Repository/CoatingRepositoryInterface.php` — add:

```php
/**
 * @param list<string> $ids
 * @return list<Coating> возвращает в том же порядке, что и $ids; отсутствующие id просто опущены
 */
public function findByIds(array $ids): array;
```

- [ ] **Step 3: Implement `findByIds` in concrete repo**

Edit `app/src/Coatings/Infrastructure/Repository/CoatingRepository.php` — add:

```php
public function findByIds(array $ids): array
{
    if ($ids === []) {
        return [];
    }
    /** @var array<string, Coating> $byId */
    $byId = [];
    foreach ($this->findBy(['id' => $ids]) as $coating) {
        $byId[$coating->getId()] = $coating;
    }
    $ordered = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $ordered[] = $byId[$id];
        }
    }
    return $ordered;
}
```

- [ ] **Step 4: Create the Query class**

Create `app/src/Coatings/Application/UseCase/Query/GetCoatingsByIds/GetCoatingsByIdsQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingsByIds;

use App\Shared\Application\Query\Query;

final readonly class GetCoatingsByIdsQuery extends Query
{
    /** @param list<string> $ids */
    public function __construct(public array $ids)
    {
    }
}
```

- [ ] **Step 5: Create the Result class**

Create `app/src/Coatings/Application/UseCase/Query/GetCoatingsByIds/GetCoatingsByIdsQueryResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingsByIds;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;

final readonly class GetCoatingsByIdsQueryResult
{
    /** @param list<CoatingDTO> $coatings */
    public function __construct(public array $coatings)
    {
    }
}
```

- [ ] **Step 6: Create the Handler**

Create `app/src/Coatings/Application/UseCase/Query/GetCoatingsByIds/GetCoatingsByIdsQueryHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingsByIds;

use App\Coatings\Application\DTO\Coatings\CoatingDTOTransformer;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetCoatingsByIdsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface $coatingRepository,
        private CoatingDTOTransformer      $coatingDTOTransformer,
    ) {
    }

    public function __invoke(GetCoatingsByIdsQuery $query): GetCoatingsByIdsQueryResult
    {
        $coatings = $this->coatingRepository->findByIds($query->ids);
        $dtos = array_map(
            fn($coating) => $this->coatingDTOTransformer->fromEntity($coating),
            $coatings,
        );
        return new GetCoatingsByIdsQueryResult($dtos);
    }
}
```

- [ ] **Step 7: Run full unit suite to verify nothing broke**

```bash
vendor/bin/phpunit tests/Unit
```

Expected: all green. (No new unit test for query handler — it's thin orchestration; functional test in Task 8 covers it end-to-end.)

- [ ] **Step 8: Commit checkpoint (user)**

User commits: 3 new query files + 2 modified repo files. Suggested message: `feat: add GetCoatingsByIds query and repository method`.

---

### Task 3: Extract `recoating_pair_table` macro into a shared partial

**Files:**
- Create: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_recoating_pair_table.html.twig`
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig`

**Interfaces:**
- Consumes: nothing new.
- Produces: a Twig partial that renders a `(temperature, min, max)` pair-table for a single tree node. Used by both the existing list-modal and the new compare page (Task 5).

This is a pure refactor — output of `index.html.twig` must not change.

- [ ] **Step 1: Read the existing macro in `index.html.twig`**

The macro `recoating_pair_table(heading, minPoints, maxPoints)` is defined at the top of `index.html.twig` (between `{% extends %}` and `{% block title %}`). It's called via `_self.recoating_pair_table(...)` from within the modal section.

- [ ] **Step 2: Create the partial file**

Create `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_recoating_pair_table.html.twig`:

```twig
{# Read-only таблица min/max-точек одного узла дерева перекрытия (пара спарена по температуре).
   Параметры:
     - heading: подпись над таблицей
     - minPoints: list<DryingTimePointDTO>
     - maxPoints: list<DryingTimePointDTO>
#}
{% macro render(heading, minPoints, maxPoints) %}
    {% if minPoints is not empty or maxPoints is not empty %}
        <div class="mt-3">
            <div class="text-muted small mb-1">{{ heading }}</div>
            {% set maxByTemp = {} %}
            {% for p in maxPoints %}
                {% set maxByTemp = maxByTemp|merge({(p.temperature_at): p}) %}
            {% endfor %}
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th class="text-muted fw-normal" style="width: 30%;">Температура</th>
                        <th class="text-muted fw-normal">Минимальный</th>
                        <th class="text-muted fw-normal">Максимальный</th>
                    </tr>
                </thead>
                <tbody>
                    {% for point in minPoints %}
                        {% set maxPoint = maxByTemp[point.temperature_at] ?? null %}
                        <tr {% if point.is_calculated %}class="table-warning"{% endif %}>
                            <td>+{{ point.temperature_at }} °C</td>
                            <td>
                                {{ point.time_in_minutes|duration_minutes }}
                                {% if point.is_calculated %}
                                    <span class="badge text-bg-warning ms-1" title="Расчётное значение">расчёт</span>
                                {% endif %}
                            </td>
                            <td>
                                {% if maxPoint %}
                                    {{ maxPoint.time_in_minutes|duration_minutes }}
                                    {% if maxPoint.is_calculated %}
                                        <span class="badge text-bg-warning ms-1" title="Расчётное значение">расчёт</span>
                                    {% endif %}
                                {% else %}
                                    <span class="text-muted">—</span>
                                {% endif %}
                            </td>
                        </tr>
                    {% endfor %}
                </tbody>
            </table>
        </div>
    {% endif %}
{% endmacro %}
```

- [ ] **Step 3: Replace inline macro in `index.html.twig`**

Edit `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig`:

Delete the entire inline `{% macro recoating_pair_table(...) %}...{% endmacro %}` block at the top of the file.

Add at top of file (just below `{% extends ... %}`):

```twig
{% import 'admin/coating/coating/_recoating_pair_table.html.twig' as pairTable %}
```

Replace every `_self.recoating_pair_table(...)` call inside the modal with `pairTable.render(...)`. There are three such calls (root, env, env→base headers).

- [ ] **Step 4: Visual smoke check**

Open the coatings list in the browser; open a coating's preview modal. Verify recoating interval tables render identically (root «Общее», env headers, base headers). No layout regression.

- [ ] **Step 5: Run full unit suite**

```bash
vendor/bin/phpunit tests/Unit
```

Expected: all green (Twig templates not unit-tested; sanity check that nothing else broke).

- [ ] **Step 6: Commit checkpoint (user)**

User commits: 1 new partial + 1 modified template. Suggested message: `refactor: extract recoating_pair_table macro to shared partial`.

---

### Task 4: CompareAction controller + skeleton template

**Files:**
- Create: `app/src/Coatings/Infrastructure/Controller/Coating/CompareAction.php`
- Create: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/compare.html.twig` (skeleton only — full rendering in Task 5)

**Interfaces:**
- Consumes: `QueryBus`, `ObjectComparator`, `GetCoatingsByIdsQuery`.
- Produces: HTTP route `GET /cabinet/coating/coating/compare?ids=a,b,c` (name `app_cabinet_coating_coating_compare`).

- [ ] **Step 1: Read existing controller pattern**

Read `app/src/Coatings/Infrastructure/Controller/Coating/ListAction.php` for the controller style (constructor injection, QueryBus, render, flash).

- [ ] **Step 2: Create the CompareAction**

Create `app/src/Coatings/Infrastructure/Controller/Coating/CompareAction.php`:

```php
<?php
declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\GetCoatingsByIds\GetCoatingsByIdsQuery;
use App\Shared\Application\Comparison\ComparisonConfig;
use App\Shared\Application\Comparison\ObjectComparator;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coating/coating/compare',
    name: 'app_cabinet_coating_coating_compare',
    methods: ['GET'],
)]
final class CompareAction extends AbstractController
{
    private const MAX_ITEMS = 4;

    // Поля покрытия, по которым строится сравнение. Подписи и форматирование — в шаблоне.
    private const FIELDS = [
        'title',
        'manufacturer.title',
        'base',
        'volumeSolid',
        'massDensity',
        'pack',
        'thinner',
        'applicationMinTemp',
        'dftRange.min',
        'dftRange.max',
        'dftRange.tds_dft',
        'dryToTouch',
        'fullCure',
        'minRecoatingInterval',
        'maxRecoatingInterval',
    ];

    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly ObjectComparator  $comparator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $idsParam = trim((string) $request->query->get('ids', ''));
        $ids = $idsParam === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $idsParam))));

        if (count($ids) < 2) {
            $this->addFlash('compare_error', 'Выберите минимум 2 покрытия для сравнения.');
            return $this->redirectToRoute('app_cabinet_coating_coating_list');
        }
        if (count($ids) > self::MAX_ITEMS) {
            $ids = array_slice($ids, 0, self::MAX_ITEMS);
        }

        /** @var \App\Coatings\Application\UseCase\Query\GetCoatingsByIds\GetCoatingsByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetCoatingsByIdsQuery($ids));
        $subjects = $result->coatings;

        if (count($subjects) < 2) {
            $this->addFlash('compare_error', 'Не удалось загрузить выбранные покрытия (возможно, часть была удалена).');
            return $this->redirectToRoute('app_cabinet_coating_coating_list');
        }

        $comparison = $this->comparator->compare(new ComparisonConfig(self::FIELDS), ...$subjects);

        return $this->render('admin/coating/coating/compare.html.twig', [
            'subjects'   => $subjects,
            'comparison' => $comparison,
            'fields'     => self::FIELDS,
        ]);
    }
}
```

- [ ] **Step 3: Create skeleton template**

Create `app/src/Shared/Infrastructure/Templates/admin/coating/coating/compare.html.twig`:

```twig
{% extends '/cabinet/index.html.twig' %}

{% block title %}{{ parent() }} | Сравнение покрытий{% endblock %}

{% block content %}
    <div class="container-fluid p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">Сравнение покрытий ({{ subjects|length }})</h1>
            <a href="{{ path('app_cabinet_coating_coating_list') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> К списку
            </a>
        </div>

        {# TODO Task 5: полный рендер таблицы со всеми форматтерами и сайдбаром-фильтром #}
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Поле</th>
                    {% for subject in subjects %}
                        <th>{{ subject.title }}</th>
                    {% endfor %}
                </tr>
            </thead>
            <tbody>
                {% for row in comparison.rows %}
                    <tr {% if row.isDifferent %}class="table-warning"{% endif %}>
                        <th>{{ row.field }}</th>
                        {% for value in row.values %}
                            <td>{{ value is iterable ? '[...]' : value }}</td>
                        {% endfor %}
                    </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
{% endblock %}
```

(`TODO` here is intentional — Task 5 replaces the table body with proper formatters; this skeleton lets us smoke-test the controller in isolation.)

- [ ] **Step 4: Manual smoke check in browser**

1. Pick two valid coating IDs from the list page (look at the row URLs).
2. Navigate to `/cabinet/coating/coating/compare?ids=<id1>,<id2>`.
3. Verify the page renders: 2 columns with coating titles, one row per field, warning highlight on differing rows.
4. Try `?ids=<single-id>` → should redirect to list with flash error.
5. Try `?ids=<bad-uuid>` → flash error.

- [ ] **Step 5: Run full unit suite (regression check)**

```bash
vendor/bin/phpunit tests/Unit
```

Expected: all green.

- [ ] **Step 6: Commit checkpoint (user)**

User commits: new controller + skeleton template. Suggested message: `feat: add compare action for coatings (skeleton)`.

---

### Task 5: Compare page — full template with labels, formatters, sidebar

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/compare.html.twig`

**Interfaces:**
- Consumes: `pairTable.render` macro from `_recoating_pair_table.html.twig` (Task 3); `duration_minutes` Twig filter (existing).
- Produces: full compare page with sticky-first-column table, formatters per field type, sidebar with field-filter checkboxes (UI only — toggle logic in Task 7).

- [ ] **Step 1: Replace skeleton with full template**

Replace the entire contents of `app/src/Shared/Infrastructure/Templates/admin/coating/coating/compare.html.twig` with:

```twig
{% extends '/cabinet/index.html.twig' %}
{% import 'admin/coating/coating/_recoating_pair_table.html.twig' as pairTable %}

{% block title %}{{ parent() }} | Сравнение покрытий{% endblock %}

{% block content %}
    {% set fieldLabels = {
        'title':                  'Название',
        'manufacturer.title':     'Производитель',
        'base':                   'Тип ЛКМ (ISO)',
        'volumeSolid':            'Сухой остаток, %',
        'massDensity':            'Плотность, кг/л',
        'pack':                   'Упаковка, л',
        'thinner':                'Разбавитель',
        'applicationMinTemp':     'Мин Т нанесения, °C',
        'dftRange.min':           'Мин ТСП, мкм',
        'dftRange.max':           'Макс ТСП, мкм',
        'dftRange.tds_dft':       'Целевая ТСП, мкм',
        'dryToTouch':             'Сухой на отлип',
        'fullCure':               'Полное отверждение',
        'minRecoatingInterval':   'Интервал перекрытия — минимальный',
        'maxRecoatingInterval':   'Интервал перекрытия — максимальный',
    } %}

    <div class="container-fluid p-4" data-controller="compare-filter">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">Сравнение покрытий ({{ subjects|length }})</h1>
            <a href="{{ path('app_cabinet_coating_coating_list') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> К списку
            </a>
        </div>

        <div class="row g-3">
            {# Сайдбар: чекбоксы видимых полей. Логика toggle — Stimulus в Task 7. #}
            <aside class="col-lg-3">
                <div class="card">
                    <div class="card-header fw-semibold small">Видимые поля</div>
                    <ul class="list-group list-group-flush">
                        {% for field in fields %}
                            <li class="list-group-item py-1">
                                <label class="form-check d-flex align-items-center gap-2 mb-0">
                                    <input type="checkbox" class="form-check-input m-0"
                                           data-compare-filter-target="checkbox"
                                           data-field="{{ field }}" checked>
                                    <span class="small">{{ fieldLabels[field] ?? field }}</span>
                                </label>
                            </li>
                        {% endfor %}
                    </ul>
                </div>
            </aside>

            <main class="col-lg-9">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 20%;">Поле</th>
                                {% for subject in subjects %}
                                    <th>
                                        <div class="fw-semibold">{{ subject.title }}</div>
                                        <div class="text-muted small">{{ subject.manufacturer.title }}</div>
                                    </th>
                                {% endfor %}
                            </tr>
                        </thead>
                        <tbody>
                            {% for row in comparison.rows %}
                                <tr data-compare-filter-target="row" data-field="{{ row.field }}"
                                    {% if row.isDifferent %}class="table-warning"{% endif %}>
                                    <th scope="row">{{ fieldLabels[row.field] ?? row.field }}</th>
                                    {% for value in row.values %}
                                        <td>{{ _self.format_value(row.field, value) }}</td>
                                    {% endfor %}
                                </tr>
                            {% endfor %}
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>
{% endblock %}

{# Per-field форматтер. Знает про специфические DTO-типы. #}
{% macro format_value(field, value) %}
    {% if value is null %}
        <span class="text-muted">—</span>
    {% elseif field == 'dryToTouch' or field == 'fullCure' %}
        {% if value is empty %}
            <span class="text-muted">—</span>
        {% else %}
            <ul class="list-unstyled mb-0 small">
                {% for p in value %}
                    <li>+{{ p.temperature_at }} °C — {{ p.time_in_minutes|duration_minutes }}</li>
                {% endfor %}
            </ul>
        {% endif %}
    {% elseif field == 'minRecoatingInterval' or field == 'maxRecoatingInterval' %}
        {% if value is null %}
            <span class="text-muted">Без верхней границы</span>
        {% else %}
            {{ _self.render_tree(value) }}
        {% endif %}
    {% else %}
        {{ value }}
    {% endif %}
{% endmacro %}

{# Рекурсивный рендер дерева перекрытия: root + env + base. #}
{% macro render_tree(tree) %}
    {% import 'admin/coating/coating/_recoating_pair_table.html.twig' as pairTable %}
    {% set envLabels = {atmospheric: 'Атмосферная', immersion: 'Погружение', special: 'Спец среды'} %}

    {{ pairTable.render('Общее', tree.default, []) }}

    {% for envKey, envNode in (tree.branches ?? {}) %}
        {% set envLabel = envLabels[envKey] ?? envKey %}
        {{ pairTable.render(envLabel, envNode.default, []) }}
        {% for baseKey, baseNode in (envNode.branches ?? {}) %}
            {{ pairTable.render(envLabel ~ ' → ' ~ baseKey|upper, baseNode.default, []) }}
        {% endfor %}
    {% endfor %}
{% endmacro %}
```

Note: in the compare context we pass empty `maxPoints` to `pairTable.render` because each row already pairs min/max across coatings as separate columns; we display only the «min-tree» values per coating cell. Full-fledged min/max-pair inside a single cell would be overwhelming — leave it as a side-by-side row instead.

- [ ] **Step 2: Manual smoke check**

1. Pick 2 coatings with **different** values (e.g., different `volumeSolid`, different `dftRange.tds_dft`).
2. Navigate to `/cabinet/coating/coating/compare?ids=<a>,<b>`.
3. Verify: row labels are Russian; differing rows are yellow; `dryToTouch` shows «+20 °C — 1 ч»; `minRecoatingInterval` shows tree as nested pair-tables.
4. Sidebar checkboxes appear but don't yet toggle anything — that's Task 7.

- [ ] **Step 3: Run full unit suite (regression check)**

```bash
vendor/bin/phpunit tests/Unit
```

Expected: all green.

- [ ] **Step 4: Commit checkpoint (user)**

User commits: full compare template. Suggested message: `feat: full compare page template with labels and per-field formatters`.

---

### Task 6: `compare_tray_controller.js` + list-page integration

**Files:**
- Create: `app/assets/controllers/compare_tray_controller.js`
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig` (add `+ в сравнение` button per row + sticky tray bar)

**Interfaces:**
- Consumes: localStorage key `compare:Coating` (`list<string>`).
- Produces: Stimulus controller `compare-tray` with actions `add` (param: id), `remove` (param: id), `clear`, `open`; targets `bar`, `count`, `openBtn`.

- [ ] **Step 1: Write the Stimulus controller**

Create `app/assets/controllers/compare_tray_controller.js`:

```js
import { Controller } from '@hotwired/stimulus';

/**
 * Tray для набора покрытий к сравнению. Состояние — localStorage по ключу 'compare:Coating'.
 * Лимит — 4. Открывает /cabinet/coating/coating/compare?ids=...
 */
export default class extends Controller {
    static targets = ['bar', 'count', 'openBtn'];
    static values = {
        storageKey: { type: String, default: 'compare:Coating' },
        compareUrl: { type: String, default: '/cabinet/coating/coating/compare' },
        max:        { type: Number, default: 4 },
    };

    connect() {
        this._sync();
        // Реагировать на изменения из других вкладок.
        window.addEventListener('storage', this._onStorage = (e) => {
            if (e.key === this.storageKeyValue) this._sync();
        });
    }

    disconnect() {
        if (this._onStorage) window.removeEventListener('storage', this._onStorage);
    }

    add(event) {
        const id = event.params.id;
        if (!id) return;
        const ids = this._read();
        if (ids.includes(id)) return;
        if (ids.length >= this.maxValue) {
            alert(`Можно сравнить максимум ${this.maxValue} покрытия.`);
            return;
        }
        ids.push(id);
        this._write(ids);
        this._reflectButton(id, true);
    }

    remove(event) {
        const id = event.params.id;
        if (!id) return;
        const ids = this._read().filter(x => x !== id);
        this._write(ids);
        this._reflectButton(id, false);
    }

    clear() {
        this._write([]);
        this.element.querySelectorAll('[data-compare-id]').forEach(btn => {
            this._reflectButton(btn.dataset.compareId, false);
        });
    }

    open() {
        const ids = this._read();
        if (ids.length < 2) {
            alert('Выберите минимум 2 покрытия.');
            return;
        }
        window.location.href = `${this.compareUrlValue}?ids=${ids.join(',')}`;
    }

    _sync() {
        const ids = this._read();
        if (this.hasCountTarget) this.countTarget.textContent = String(ids.length);
        if (this.hasBarTarget) this.barTarget.classList.toggle('d-none', ids.length === 0);
        if (this.hasOpenBtnTarget) this.openBtnTarget.disabled = ids.length < 2;
        // Отметить уже добавленные кнопки.
        this.element.querySelectorAll('[data-compare-id]').forEach(btn => {
            this._reflectButton(btn.dataset.compareId, ids.includes(btn.dataset.compareId));
        });
    }

    _reflectButton(id, isInTray) {
        const btn = this.element.querySelector(`[data-compare-id="${id}"]`);
        if (!btn) return;
        btn.classList.toggle('btn-success', isInTray);
        btn.classList.toggle('btn-outline-success', !isInTray);
        btn.innerHTML = isInTray ? '<i class="bi bi-check2"></i>' : '<i class="bi bi-plus-lg"></i>';
        btn.title = isInTray ? 'Убрать из сравнения' : 'Добавить в сравнение';
        btn.dataset.action = isInTray
            ? 'click->compare-tray#remove'
            : 'click->compare-tray#add';
    }

    _read() {
        try {
            const raw = window.localStorage.getItem(this.storageKeyValue);
            return raw ? JSON.parse(raw) : [];
        } catch {
            return [];
        }
    }

    _write(ids) {
        window.localStorage.setItem(this.storageKeyValue, JSON.stringify(ids));
        this._sync();
    }
}
```

- [ ] **Step 2: Wire the controller into the list page**

Edit `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig`:

A) Wrap the page content in a `data-controller="compare-tray"` element. Find the outer `<div class="col-lg-10 mx-auto p-4 py-md-5">` (or similar root content div in the file) and add the data-controller + data-values:

```twig
<div class="col-lg-10 mx-auto p-4 py-md-5"
     data-controller="compare-tray"
     data-compare-tray-storage-key-value="compare:Coating"
     data-compare-tray-compare-url-value="{{ path('app_cabinet_coating_coating_compare') }}"
     data-compare-tray-max-value="4">
```

B) Add the «+ в сравнение» button on each coating row. Find the row-action cell in the coatings table loop (the cell with the edit/delete buttons) and add **before** the existing buttons:

```twig
<button type="button" class="btn btn-sm btn-outline-success"
        data-compare-id="{{ coating.id }}"
        data-action="click->compare-tray#add"
        data-compare-tray-id-param="{{ coating.id }}"
        title="Добавить в сравнение">
    <i class="bi bi-plus-lg"></i>
</button>
```

C) Add a sticky bar at the end of the wrapper div (just before its closing `</div>`):

```twig
<div class="position-fixed bottom-0 start-50 translate-middle-x mb-3 d-none"
     style="z-index: 1080;"
     data-compare-tray-target="bar">
    <div class="card shadow">
        <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
            <span class="small">К сравнению: <strong data-compare-tray-target="count">0</strong></span>
            <button type="button" class="btn btn-sm btn-primary"
                    data-action="click->compare-tray#open"
                    data-compare-tray-target="openBtn" disabled>
                Сравнить
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-action="click->compare-tray#clear">
                Очистить
            </button>
        </div>
    </div>
</div>
```

- [ ] **Step 3: Rebuild assets**

```bash
yarn dev
```

Expected: `webpack compiled successfully`.

- [ ] **Step 4: Manual smoke check**

1. Hard-reload the coatings list page.
2. Click «+» on coating A — button turns green, sticky bar shows «К сравнению: 1», «Сравнить» disabled.
3. Click «+» on coating B — bar shows 2, «Сравнить» enabled.
4. Click «+» on coatings C, D, E — at E an alert «Можно сравнить максимум 4».
5. Click «Сравнить» → navigates to `/compare?ids=A,B,C,D`.
6. Refresh list page → previously added are still highlighted (localStorage persists).
7. Click «Очистить» → all unmarked, bar hides.

- [ ] **Step 5: Run full unit suite (regression check)**

```bash
vendor/bin/phpunit tests/Unit
```

Expected: all green.

- [ ] **Step 6: Commit checkpoint (user)**

User commits: new JS controller + modified list template + rebuilt assets. Suggested message: `feat: compare tray on coatings list with localStorage persistence`.

---

### Task 7: `compare_filter_controller.js` (sidebar field toggle)

**Files:**
- Create: `app/assets/controllers/compare_filter_controller.js`

**Interfaces:**
- Consumes: localStorage key `compare:fields:Coating` (`list<string>` — visible fields).
- Produces: Stimulus controller `compare-filter` with actions/targets matching the template wired in Task 5 (`checkbox` targets + `row` targets).

- [ ] **Step 1: Write the controller**

Create `app/assets/controllers/compare_filter_controller.js`:

```js
import { Controller } from '@hotwired/stimulus';

/**
 * Сайдбар-фильтр compare-страницы: чекбоксы скрывают/показывают строки сравнения.
 * Состояние видимых полей хранится в localStorage по ключу 'compare:fields:Coating'
 * (sticky между визитами). По умолчанию все включены.
 */
export default class extends Controller {
    static targets = ['checkbox', 'row'];
    static values = {
        storageKey: { type: String, default: 'compare:fields:Coating' },
    };

    connect() {
        const stored = this._read();
        if (stored !== null) {
            // Применить ранее сохранённый выбор.
            this.checkboxTargets.forEach(cb => {
                cb.checked = stored.includes(cb.dataset.field);
            });
        }
        this.checkboxTargets.forEach(cb => cb.addEventListener('change', () => this._apply()));
        this._apply();
    }

    _apply() {
        const visible = new Set(
            this.checkboxTargets.filter(cb => cb.checked).map(cb => cb.dataset.field),
        );
        this.rowTargets.forEach(row => {
            row.classList.toggle('d-none', !visible.has(row.dataset.field));
        });
        this._write([...visible]);
    }

    _read() {
        try {
            const raw = window.localStorage.getItem(this.storageKeyValue);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    }

    _write(visible) {
        window.localStorage.setItem(this.storageKeyValue, JSON.stringify(visible));
    }
}
```

- [ ] **Step 2: Rebuild assets**

```bash
yarn dev
```

Expected: `webpack compiled successfully`.

- [ ] **Step 3: Manual smoke check**

1. Hard-reload the compare page (`/cabinet/coating/coating/compare?ids=...`).
2. Toggle off «Сухой остаток, %» → the row disappears.
3. Toggle it back → the row reappears.
4. Refresh the page → previously hidden row remains hidden (localStorage persists).
5. Open compare for a different pair of coatings (different `?ids=`) → same hidden fields stay hidden.

- [ ] **Step 4: Run full unit suite (regression check)**

```bash
vendor/bin/phpunit tests/Unit
```

Expected: all green.

- [ ] **Step 5: Commit checkpoint (user)**

User commits: new filter JS + rebuilt assets. Suggested message: `feat: compare page field-filter sidebar with sticky selection`.

---

### Task 8: Functional test for CompareAction

**Files:**
- Create: `app/tests/Functional/Coatings/Infrastructure/Controller/Coating/CompareActionTest.php`

**Interfaces:**
- Consumes: existing fixture pattern from `tests/Functional/Coatings/Infrastructure/Controller/Coating/UpdateActionRecoatingTreeTest.php` (WebTestCase + auth setup + creating coating + manufacturer + user).
- Produces: 3 test methods covering happy path + 2 edge cases.

- [ ] **Step 1: Read the existing test for fixture/auth pattern**

Read `app/tests/Functional/Coatings/Infrastructure/Controller/Coating/UpdateActionRecoatingTreeTest.php` end-to-end. Copy the `setUp()` pattern (client, EM, user/auth, manufacturer creation) verbatim — same admin-only access.

- [ ] **Step 2: Create the test file**

Create `app/tests/Functional/Coatings/Infrastructure/Controller/Coating/CompareActionTest.php`. Use the same `setUp()` pattern as `UpdateActionRecoatingTreeTest`. Add three test methods:

```php
public function testRendersComparisonTableForTwoCoatings(): void
{
    $idA = $this->createCoating('Coating A', volumeSolid: 50);
    $idB = $this->createCoating('Coating B', volumeSolid: 70);

    $this->client->request('GET', sprintf('/cabinet/coating/coating/compare?ids=%s,%s', $idA, $idB));

    self::assertResponseIsSuccessful();
    $content = $this->client->getResponse()->getContent();
    self::assertStringContainsString('Сравнение покрытий (2)', $content);
    self::assertStringContainsString('Coating A', $content);
    self::assertStringContainsString('Coating B', $content);
    // Различия по volumeSolid должны быть подсвечены.
    self::assertMatchesRegularExpression(
        '/<tr[^>]*class="[^"]*table-warning[^"]*"[^>]*data-field="volumeSolid"/',
        $content,
    );
}

public function testRedirectsWhenFewerThanTwoIds(): void
{
    $idA = $this->createCoating('Solo Coating');

    $this->client->request('GET', '/cabinet/coating/coating/compare?ids=' . $idA);

    self::assertResponseRedirects('/cabinet/coating/coating');
    $session = $this->client->getRequest()->getSession();
    self::assertContains(
        'Выберите минимум 2 покрытия для сравнения.',
        $session->getFlashBag()->peek('compare_error'),
    );
}

public function testRedirectsWhenAllIdsMissing(): void
{
    $fakeA = '00000000-0000-0000-0000-000000000001';
    $fakeB = '00000000-0000-0000-0000-000000000002';

    $this->client->request('GET', sprintf('/cabinet/coating/coating/compare?ids=%s,%s', $fakeA, $fakeB));

    self::assertResponseRedirects('/cabinet/coating/coating');
}
```

Extract the coating creation into a helper `createCoating(string $title, int $volumeSolid = 60): string` that returns the new coating id — mirror what `UpdateActionRecoatingTreeTest::setUp()` already does for a single coating, but parameterize title and `volumeSolid`. Use defaults for the rest (DftRange, DryingTimeSeries, recoating trees) — same way the existing test does.

- [ ] **Step 3: Run the new functional test**

```bash
vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/Coating/CompareActionTest.php
```

Expected: 3 tests, all green. If they fail, the most likely cause is a fixture mismatch — re-check the `createCoating` helper against `UpdateActionRecoatingTreeTest::setUp()`.

- [ ] **Step 4: Run full test suite (final regression check)**

```bash
vendor/bin/phpunit
```

Expected: all green across `tests/Unit` and `tests/Functional`.

- [ ] **Step 5: Commit checkpoint (user)**

User commits: functional test. Suggested message: `test: functional coverage for compare action`.

---

## Done

After Task 8: the comparison service is shipped end-to-end for coatings. To add the next consumer (CoatingSystem, …) only Task 4 + 5 + 6 + 7 + 8 patterns repeat per type — the `Shared/Application/Comparison/*` from Task 1 doesn't change.
