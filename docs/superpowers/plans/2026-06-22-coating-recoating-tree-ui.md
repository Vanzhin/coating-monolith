# Coating Recoating Interval Tree UI — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Полноценный UI редактирования 3-уровневого дерева интервалов перекрытия (`default → среда → основа следующего ЛКМ`) в существующей форме покрытия, с симметричными min/max-деревьями.

**Architecture:** Дерево едет с формы на сервер вложенными `name=` атрибутами (`minRecoatingInterval[branches][atmospheric][default][points][0][temperature_at]`), серверная сторона строится плоским рекурсивным DTO (`RecoatingIntervalNodeDTO`) и собирается через `RecoatingIntervalTree::withChild`. Twig-партиал `_recoating_node.html.twig` рекурсивно рендерит уровни; Stimulus-контроллер `coating-form` динамически добавляет/удаляет вкладки сред и блоки оснований.

**Tech Stack:** PHP 8.x, Symfony 6/7, Doctrine ORM, Twig, Stimulus, Bootstrap 5, PHPUnit 9.

## Global Constraints

- **Дизайн-документ:** `docs/superpowers/specs/2026-06-22-coating-recoating-tree-ui-design.md` — все правила брать оттуда.
- **Только редакторская форма.** Read-only отображения интервалов в списке покрытий и в карточке — вне scope.
- **Симметрия min/max** на уровне tabs: добавление/удаление среды или основания в UI делается одним кликом и затрагивает обе серии. Семантическая асимметрия (max-tree может быть плоским при пустых max-точках) разрешена.
- **Пустые узлы дерева:**
  - пустая ветка (нет точек в default, нет потомков) — игнорируется при сборке;
  - пустой default при наличии потомков — `AppException` («Серия по умолчанию для узла "X" не может быть пустой, если есть исключения.»);
  - пустой root `min`-tree — `AppException` (мин. интервал обязателен);
  - пустой root `max`-tree без потомков — `null`.
- **Никаких git commit'ов агентом.** Пользователь сам коммитит — в конце каждой задачи стоп-маркер «🛑 Stop for user review/commit».
- **PHPUnit** через `./bin/phpunit` из корня `app/`.
- **Существующие тесты** (`RecoatingIntervalTreeTest`, `CoatingTest`, `RecoatingIntervalTreeTypeTest`) после рефакторинга должны остаться зелёными.

---

## File Structure

**Создаются:**
- `app/src/Coatings/Application/DTO/Coatings/RecoatingIntervalNodeDTO.php` — плоский рекурсивный DTO для одного узла дерева.
- `app/src/Coatings/Application/UseCase/Command/RecoatingTreeBuilder.php` — общий сервис, собирающий `?RecoatingIntervalTree` из `RecoatingIntervalNodeDTO` для обоих хендлеров.
- `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_recoating_node.html.twig` — рекурсивный партиал рендера узла.
- `app/tests/Unit/Coatings/Application/DTO/Coatings/CoatingDTOTransformerTest.php` — unit-тест трансформера (защита от регрессии: исключения дерева не теряются).
- `app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingMapperTest.php` — unit-тест мэппера (round-trip nested array ↔ DTO).
- `app/tests/Unit/Coatings/Application/UseCase/Command/RecoatingTreeBuilderTest.php` — unit-тест билдера дерева.
- `app/tests/Functional/Coatings/Infrastructure/Controller/Coating/UpdateActionRecoatingTreeTest.php` — functional round-trip тест.

**Изменяются:**
- `app/src/Coatings/Application/DTO/Coatings/CoatingDTO.php` — типы полей `min/maxRecoatingInterval` меняются на `RecoatingIntervalNodeDTO`/`?RecoatingIntervalNodeDTO`.
- `app/src/Coatings/Application/DTO/Coatings/CoatingDTOTransformer.php` — `fromEntity` обходит дерево рекурсивно.
- `app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php` — `buildCoatingDtoFromInputData`, `buildInputDataFromDto`, `getValidationCollectionCoating`.
- `app/src/Coatings/Application/UseCase/Command/CreateCoating/CreateCoatingCommandHandler.php` — использует `buildRecoatingTree`.
- `app/src/Coatings/Application/UseCase/Command/UpdateCoating/UpdateCoatingCommandHandler.php` — то же.
- `app/src/Shared/Infrastructure/Templates/admin/coating/coating/form.html.twig` — секция «Интервал перекрытия» заменена на `include` партиала.
- `app/assets/controllers/coating_form_controller.js` — новые actions `addEnv/removeEnv/addBase/removeBase`; существующий парсинг имён полей переделан через path в DOM, не через regex.

---

## Task 1: New DTO — `RecoatingIntervalNodeDTO`

**Files:**
- Create: `app/src/Coatings/Application/DTO/Coatings/RecoatingIntervalNodeDTO.php`

**Interfaces:**
- Produces: `final class RecoatingIntervalNodeDTO { public array $default = []; public array $branches = []; }` — `default` is `list<DryingTimePointDTO>`, `branches` is `array<string, RecoatingIntervalNodeDTO>`.

- [ ] **Step 1: Create the DTO file**

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

final class RecoatingIntervalNodeDTO
{
    /** @var list<DryingTimePointDTO> */
    public array $default = [];

    /** @var array<string, RecoatingIntervalNodeDTO> */
    public array $branches = [];
}
```

- [ ] **Step 2: Verify class loads**

Run: `./bin/phpunit --filter NonExistentTest 2>&1 | tail -5`
Expected: PHPUnit стартует без fatal-ошибок, говорит «No tests executed». (Это валидирует, что autoload не падает на новом классе.)

- [ ] **Step 3: 🛑 Stop for user review/commit**

---

## Task 2: Transformer — обход дерева

**Files:**
- Modify: `app/src/Coatings/Application/DTO/Coatings/CoatingDTO.php` — поменять типы полей recoating-интервалов.
- Modify: `app/src/Coatings/Application/DTO/Coatings/CoatingDTOTransformer.php` — `fromEntity` рекурсивно обходит дерево.
- Create: `app/tests/Unit/Coatings/Application/DTO/Coatings/CoatingDTOTransformerTest.php`

**Interfaces:**
- Consumes: `RecoatingIntervalNodeDTO` (Task 1).
- Produces: `CoatingDTOTransformer::fromEntity(Coating): CoatingDTO` — DTO теперь несёт `RecoatingIntervalNodeDTO $minRecoatingInterval` и `?RecoatingIntervalNodeDTO $maxRecoatingInterval`.

- [ ] **Step 1: Write failing test for branch preservation**

Создать тест-файл `app/tests/Unit/Coatings/Application/DTO/Coatings/CoatingDTOTransformerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\DTO\Coatings;

use App\Coatings\Application\DTO\Coatings\CoatingDTOTransformer;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalNodeDTO;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\Specification\UniqueTitleCoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use PHPUnit\Framework\TestCase;

final class CoatingDTOTransformerTest extends TestCase
{
    public function testFromEntityPreservesMinRecoatingTreeBranches(): void
    {
        $rootDef = new DryingTimeSeries(new TimeAtTemperature(20, 60));
        $atmDef  = new DryingTimeSeries(new TimeAtTemperature(20, 30));
        $epDef   = new DryingTimeSeries(new TimeAtTemperature(20, 15));

        $minTree = new RecoatingIntervalTree(
            $rootDef,
            'default',
            new RecoatingIntervalTree(
                $atmDef,
                'atmospheric',
                new RecoatingIntervalTree($epDef, 'EP'),
            ),
        );

        $coating = $this->makeCoating(min: $minTree, max: null);
        $dto = (new CoatingDTOTransformer())->fromEntity($coating);

        $this->assertInstanceOf(RecoatingIntervalNodeDTO::class, $dto->minRecoatingInterval);
        $this->assertCount(1, $dto->minRecoatingInterval->default);
        $this->assertSame(60, $dto->minRecoatingInterval->default[0]->time_in_minutes);

        $this->assertArrayHasKey('atmospheric', $dto->minRecoatingInterval->branches);
        $atm = $dto->minRecoatingInterval->branches['atmospheric'];
        $this->assertSame(30, $atm->default[0]->time_in_minutes);

        $this->assertArrayHasKey('ep', $atm->branches);
        $this->assertSame(15, $atm->branches['ep']->default[0]->time_in_minutes);
    }

    public function testFromEntityReturnsNullMaxRecoatingWhenAbsent(): void
    {
        $minTree = new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60)));
        $coating = $this->makeCoating(min: $minTree, max: null);

        $dto = (new CoatingDTOTransformer())->fromEntity($coating);

        $this->assertNull($dto->maxRecoatingInterval);
    }

    private function makeCoating(
        RecoatingIntervalTree $min,
        ?RecoatingIntervalTree $max,
    ): Coating {
        $manufacturer = $this->createMock(Manufacturer::class);
        $manufacturer->method('getId')->willReturn('00000000-0000-0000-0000-000000000001');
        $manufacturer->method('getTitle')->willReturn('Test');
        $manufacturer->method('getDescription')->willReturn('');

        $spec = new CoatingSpecification($this->createMock(UniqueTitleCoatingSpecification::class));

        return new Coating(
            UuidService::generateUuid(),
            'Test Coating',
            'desc',
            50, 1.2,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(80, 150), 100, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 24 * 60)),
            $min, $max,
            1.0, null,
            $manufacturer, $spec,
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./bin/phpunit app/tests/Unit/Coatings/Application/DTO/Coatings/CoatingDTOTransformerTest.php --colors=never 2>&1 | tail -20`
Expected: FAIL — `RecoatingIntervalNodeDTO` ещё не возвращается из трансформера (либо несовместимый тип возвращается).

- [ ] **Step 3: Update CoatingDTO field types**

В `app/src/Coatings/Application/DTO/Coatings/CoatingDTO.php` найти строки с объявлением `minRecoatingInterval` и `maxRecoatingInterval` и заменить на:

```php
public RecoatingIntervalNodeDTO $minRecoatingInterval;
public ?RecoatingIntervalNodeDTO $maxRecoatingInterval = null;
```

Добавить `use App\Coatings\Application\DTO\Coatings\RecoatingIntervalNodeDTO;` (если CoatingDTO не в этом неймспейсе) — но они оба в `App\Coatings\Application\DTO\Coatings`, так что use не нужен.

Если в CoatingDTO остался PHPDoc-комментарий вроде `/** @var list<DryingTimePointDTO> */` над этими полями — удалить.

- [ ] **Step 4: Rewrite `CoatingDTOTransformer::fromEntity` for tree traversal**

Заменить блок с `$dto->minRecoatingInterval = $this->pointsFromSeries(...)` и `$dto->maxRecoatingInterval = ...` на:

```php
$dto->minRecoatingInterval = $this->nodeFromTree($entity->getMinRecoatingInterval());
$dto->maxRecoatingInterval = $entity->getMaxRecoatingInterval() !== null
    ? $this->nodeFromTree($entity->getMaxRecoatingInterval())
    : null;
```

Добавить новый приватный метод:

```php
private function nodeFromTree(RecoatingIntervalTree $tree): RecoatingIntervalNodeDTO
{
    $node = new RecoatingIntervalNodeDTO();
    $node->default = $this->pointsFromSeries($tree->default);
    foreach ($tree->getChildren() as $key => $child) {
        $node->branches[$key] = $this->nodeFromTree($child);
    }
    return $node;
}
```

Добавить use-имена: `use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;`.

- [ ] **Step 5: Run test to verify it passes**

Run: `./bin/phpunit app/tests/Unit/Coatings/Application/DTO/Coatings/CoatingDTOTransformerTest.php --colors=never 2>&1 | tail -10`
Expected: PASS (2 tests, 6+ assertions).

- [ ] **Step 6: Run all existing unit tests to check no regressions**

Run: `./bin/phpunit app/tests/Unit --colors=never 2>&1 | tail -10`
Expected: всё OK.

- [ ] **Step 7: 🛑 Stop for user review/commit**

---

## Task 3: Mapper — input array ↔ DTO + relax validation

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php`
- Create: `app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingMapperTest.php`

**Interfaces:**
- Consumes: `RecoatingIntervalNodeDTO` (Task 1), `DryingTimePointDTO`.
- Produces:
  - `CoatingMapper::buildCoatingDtoFromInputData(array): CoatingDTO` — теперь читает nested-формат `{default:{points:[...]}, branches:{...}}` для recoating-интервалов.
  - `CoatingMapper::buildInputDataFromDto(CoatingDTO): array` — кладёт recoating-узлы как массивы той же формы.
  - `CoatingMapper::getValidationCollectionCoating(): Assert\Collection` — для recoating-интервалов делает рекурсивную проверку структуры, либо использует `Assert\Optional`-обёртки.

- [ ] **Step 1: Write failing round-trip test**

Создать `app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Mapper;

use App\Coatings\Application\DTO\Coatings\RecoatingIntervalNodeDTO;
use App\Coatings\Infrastructure\Mapper\CoatingMapper;
use PHPUnit\Framework\TestCase;

final class CoatingMapperTest extends TestCase
{
    public function testRecoatingIntervalNestedRoundTrip(): void
    {
        $mapper = new CoatingMapper();

        $input = $this->validInput([
            'minRecoatingInterval' => [
                'default' => [
                    'points' => [
                        ['temperature_at' => 20, 'days' => 0, 'hours' => 4, 'minutes' => 0],
                    ],
                ],
                'branches' => [
                    'atmospheric' => [
                        'default' => [
                            'points' => [
                                ['temperature_at' => 20, 'days' => 0, 'hours' => 3, 'minutes' => 0],
                            ],
                        ],
                        'branches' => [
                            'ep' => [
                                'default' => [
                                    'points' => [
                                        ['temperature_at' => 20, 'days' => 0, 'hours' => 2, 'minutes' => 0],
                                    ],
                                ],
                                'branches' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'maxRecoatingInterval' => [
                'default' => ['points' => []],
                'branches' => [],
            ],
        ]);

        $dto = $mapper->buildCoatingDtoFromInputData($input);

        $this->assertInstanceOf(RecoatingIntervalNodeDTO::class, $dto->minRecoatingInterval);
        $this->assertSame(4 * 60, $dto->minRecoatingInterval->default[0]->time_in_minutes);
        $this->assertArrayHasKey('atmospheric', $dto->minRecoatingInterval->branches);
        $this->assertSame(3 * 60, $dto->minRecoatingInterval->branches['atmospheric']->default[0]->time_in_minutes);
        $this->assertArrayHasKey('ep', $dto->minRecoatingInterval->branches['atmospheric']->branches);
        $this->assertSame(
            2 * 60,
            $dto->minRecoatingInterval->branches['atmospheric']->branches['ep']->default[0]->time_in_minutes,
        );
        $this->assertNull($dto->maxRecoatingInterval);

        // Back to array form.
        $reInput = $mapper->buildInputDataFromDto($dto);
        $this->assertSame(20, $reInput['minRecoatingInterval']['default']['points'][0]['temperature_at']);
        $this->assertSame(4 * 60, $reInput['minRecoatingInterval']['default']['points'][0]['time_in_minutes']);
        $this->assertArrayHasKey(
            'ep',
            $reInput['minRecoatingInterval']['branches']['atmospheric']['branches'],
        );
    }

    public function testRecoatingIntervalMissingFromInputDefaultsToEmptyNode(): void
    {
        $mapper = new CoatingMapper();
        $dto = $mapper->buildCoatingDtoFromInputData($this->validInput([]));

        $this->assertInstanceOf(RecoatingIntervalNodeDTO::class, $dto->minRecoatingInterval);
        $this->assertSame([], $dto->minRecoatingInterval->default);
        $this->assertSame([], $dto->minRecoatingInterval->branches);
        $this->assertNull($dto->maxRecoatingInterval);
    }

    /** @param array<string, mixed> $overrides */
    private function validInput(array $overrides): array
    {
        return array_merge([
            'title' => 'X', 'description' => 'desc',
            'volumeSolid' => 50, 'massDensity' => 1.2,
            'base' => 'EP',
            'minDft' => 80, 'maxDft' => 150, 'tdsDft' => 100,
            'applicationMinTemp' => 5,
            'dryToTouch' => [['temperature_at' => 20, 'days' => 0, 'hours' => 1, 'minutes' => 0]],
            'fullCure'   => [['temperature_at' => 20, 'days' => 1, 'hours' => 0, 'minutes' => 0]],
            'pack' => 1.0, 'thinner' => null,
            'manufacturer' => ['id' => '00000000-0000-0000-0000-000000000001'],
            'tags' => [],
        ], $overrides);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./bin/phpunit app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingMapperTest.php --colors=never 2>&1 | tail -20`
Expected: FAIL — mapper всё ещё ждёт плоский формат.

- [ ] **Step 3: Update `buildCoatingDtoFromInputData` for recoating intervals**

В `CoatingMapper` заменить блок:

```php
        $dto->dryToTouch = $this->buildPointsFromInput($inputData['dryToTouch'] ?? []);
        $dto->fullCure = $this->buildPointsFromInput($inputData['fullCure'] ?? []);
        $dto->minRecoatingInterval = $this->buildPointsFromInput($inputData['minRecoatingInterval'] ?? []);
        // max — необязателен. ...
        $maxRowsFilled = array_values(array_filter(
            $inputData['maxRecoatingInterval'] ?? [],
            fn(array $row) => $this->parseDurationInput($row) > 0,
        ));
        $dto->maxRecoatingInterval = $maxRowsFilled === [] ? null : $this->buildPointsFromInput($maxRowsFilled);
```

на:

```php
        $dto->dryToTouch = $this->buildPointsFromInput($inputData['dryToTouch'] ?? []);
        $dto->fullCure = $this->buildPointsFromInput($inputData['fullCure'] ?? []);
        $dto->minRecoatingInterval = $this->buildRecoatingNodeFromInput($inputData['minRecoatingInterval'] ?? []);
        $maxNode = $this->buildRecoatingNodeFromInput($inputData['maxRecoatingInterval'] ?? []);
        $dto->maxRecoatingInterval = $this->isRecoatingNodeEffectivelyEmpty($maxNode) ? null : $maxNode;
```

Добавить новые приватные методы в `CoatingMapper`:

```php
    /**
     * Рекурсивно строит RecoatingIntervalNodeDTO из nested-array формы.
     * Формат узла: `{ default: { points: [...] }, branches: { <key>: <same shape> } }`.
     * Пустые точки (все длительности 0) на любом уровне отфильтровываются.
     */
    private function buildRecoatingNodeFromInput(array $raw): RecoatingIntervalNodeDTO
    {
        $node = new RecoatingIntervalNodeDTO();

        $rawDefault = $raw['default']['points'] ?? [];
        $filtered = array_values(array_filter(
            $rawDefault,
            fn(array $row) => $this->parseDurationInput($row) > 0,
        ));
        $node->default = $this->buildPointsFromInput($filtered);

        foreach ($raw['branches'] ?? [] as $key => $childRaw) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $node->branches[$key] = $this->buildRecoatingNodeFromInput((array) $childRaw);
        }

        return $node;
    }

    /** Узел считается пустым, если у него нет default-точек и нет (рекурсивно) непустых веток. */
    private function isRecoatingNodeEffectivelyEmpty(RecoatingIntervalNodeDTO $node): bool
    {
        if ($node->default !== []) {
            return false;
        }
        foreach ($node->branches as $child) {
            if (!$this->isRecoatingNodeEffectivelyEmpty($child)) {
                return false;
            }
        }
        return true;
    }
```

Добавить `use App\Coatings\Application\DTO\Coatings\RecoatingIntervalNodeDTO;` если его ещё нет.

- [ ] **Step 4: Update `buildInputDataFromDto` for recoating intervals**

Найти в `buildInputDataFromDto` цикл:

```php
        foreach (self::TEMPERATURE_SERIES_FIELDS as $field) {
            $vars[$field] = $this->decomposeSeriesForForm($vars[$field] ?? null);
        }
```

Заменить на:

```php
        $vars['dryToTouch'] = $this->decomposeSeriesForForm($vars['dryToTouch'] ?? null);
        $vars['fullCure']   = $this->decomposeSeriesForForm($vars['fullCure'] ?? null);
        $vars['minRecoatingInterval'] = $this->decomposeRecoatingNodeForForm($vars['minRecoatingInterval'] ?? null);
        $vars['maxRecoatingInterval'] = $this->decomposeRecoatingNodeForForm($vars['maxRecoatingInterval'] ?? null);
```

Убрать константу `TEMPERATURE_SERIES_FIELDS` (она больше не используется единообразно).

Добавить приватный метод:

```php
    /**
     * Конвертирует RecoatingIntervalNodeDTO в nested-array для шаблона.
     * Если узел null — возвращает {default:{points:[]}, branches:{}}.
     */
    private function decomposeRecoatingNodeForForm(?RecoatingIntervalNodeDTO $node): array
    {
        if ($node === null) {
            return ['default' => ['points' => []], 'branches' => []];
        }
        $branches = [];
        foreach ($node->branches as $key => $child) {
            $branches[$key] = $this->decomposeRecoatingNodeForForm($child);
        }
        return [
            'default'  => ['points' => $this->decomposeSeriesForForm($node->default)],
            'branches' => $branches,
        ];
    }
```

- [ ] **Step 5: Relax validation collection for recoating intervals**

В `getValidationCollectionCoating()` найти строки:

```php
            'minRecoatingInterval' => $this->seriesFieldConstraints(required: true),
            'maxRecoatingInterval' => $this->seriesFieldConstraints(required: false),
```

Заменить на:

```php
            'minRecoatingInterval' => $this->recoatingNodeConstraints(),
            'maxRecoatingInterval' => $this->recoatingNodeConstraints(),
```

Добавить приватный метод:

```php
    /**
     * Структурная валидация одного узла дерева recoating-интервалов.
     * Допускает рекурсивную форму `{default:{points:[…]}, branches:{<key>: <same>}}`.
     * Проверка ключей сред/оснований и физических правил — на уровне домена.
     */
    private function recoatingNodeConstraints(): Assert\Optional
    {
        return new Assert\Optional([
            new Assert\Collection([
                'fields' => [
                    'default' => new Assert\Optional([
                        new Assert\Collection([
                            'fields' => [
                                'points' => new Assert\Optional($this->pointsListConstraint()),
                            ],
                            'allowExtraFields' => true,
                        ]),
                    ]),
                    'branches' => new Assert\Optional([new Assert\Type('array')]),
                ],
                'allowExtraFields' => true,
            ]),
        ]);
    }

    private function pointsListConstraint(): Assert\All
    {
        return new Assert\All([
            new Assert\Collection([
                'fields' => [
                    'temperature_at'  => [new Assert\NotBlank(), new Assert\Type('numeric')],
                    'days'            => new Assert\Optional([new Assert\Type('numeric')]),
                    'hours'           => new Assert\Optional([new Assert\Type('numeric')]),
                    'minutes'         => new Assert\Optional([new Assert\Type('numeric')]),
                    'time_in_minutes' => new Assert\Optional([new Assert\Type('numeric')]),
                    'is_calculated'   => new Assert\Optional([new Assert\Type('numeric')]),
                ],
                'allowExtraFields' => true,
            ]),
        ]);
    }
```

Старый метод `seriesFieldConstraints` остаётся (используется для `dryToTouch`/`fullCure`).

- [ ] **Step 6: Run mapper tests**

Run: `./bin/phpunit app/tests/Unit/Coatings/Infrastructure/Mapper/CoatingMapperTest.php --colors=never 2>&1 | tail -10`
Expected: PASS (2 tests).

- [ ] **Step 7: Run all unit tests for regressions**

Run: `./bin/phpunit app/tests/Unit --colors=never 2>&1 | tail -10`
Expected: всё OK.

- [ ] **Step 8: 🛑 Stop for user review/commit**

---

## Task 4: `RecoatingTreeBuilder` + интеграция в хендлеры

**Files:**
- Create: `app/src/Coatings/Application/UseCase/Command/RecoatingTreeBuilder.php`
- Create: `app/tests/Unit/Coatings/Application/UseCase/Command/RecoatingTreeBuilderTest.php`
- Modify: `app/src/Coatings/Application/UseCase/Command/CreateCoating/CreateCoatingCommandHandler.php`
- Modify: `app/src/Coatings/Application/UseCase/Command/UpdateCoating/UpdateCoatingCommandHandler.php`

**Interfaces:**
- Consumes: `RecoatingIntervalNodeDTO` (Task 1), `DryingTimePointDTO`.
- Produces: `RecoatingTreeBuilder::build(RecoatingIntervalNodeDTO $node, string $key = 'default'): ?RecoatingIntervalTree`. Возвращает `null`, если узел и все его потомки фактически пусты; бросает `AppException`, если у узла пусто `default`, но есть непустые потомки.

- [ ] **Step 1: Write failing test for the builder**

Создать `app/tests/Unit/Coatings/Application/UseCase/Command/RecoatingTreeBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\UseCase\Command;

use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalNodeDTO;
use App\Coatings\Application\UseCase\Command\RecoatingTreeBuilder;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class RecoatingTreeBuilderTest extends TestCase
{
    public function testBuildReturnsNullForFullyEmptyNode(): void
    {
        $builder = new RecoatingTreeBuilder();
        $node = new RecoatingIntervalNodeDTO();

        $this->assertNull($builder->build($node));
    }

    public function testBuildAssemblesFlatTreeFromDefaultOnly(): void
    {
        $builder = new RecoatingTreeBuilder();
        $node = new RecoatingIntervalNodeDTO();
        $node->default = [$this->point(20, 240)];

        $tree = $builder->build($node);

        $this->assertInstanceOf(RecoatingIntervalTree::class, $tree);
        $this->assertSame('default', $tree->key);
        $this->assertSame(240, $tree->default->points[0]->timeInMinutes);
        $this->assertSame([], $tree->getChildren());
    }

    public function testBuildAssemblesNestedBranches(): void
    {
        $builder = new RecoatingTreeBuilder();
        $node = new RecoatingIntervalNodeDTO();
        $node->default = [$this->point(20, 240)];
        $node->branches['atmospheric'] = $this->branch([$this->point(20, 180)], [
            'ep' => $this->branch([$this->point(20, 120)]),
        ]);

        $tree = $builder->build($node);

        $this->assertNotNull($tree);
        $atm = $tree->getChildren()['atmospheric'];
        $this->assertSame(180, $atm->default->points[0]->timeInMinutes);
        $this->assertSame(120, $atm->getChildren()['ep']->default->points[0]->timeInMinutes);
    }

    public function testBuildSkipsBranchWithEmptyDefaultAndEmptyChildren(): void
    {
        $builder = new RecoatingTreeBuilder();
        $node = new RecoatingIntervalNodeDTO();
        $node->default = [$this->point(20, 240)];
        $node->branches['atmospheric'] = new RecoatingIntervalNodeDTO();

        $tree = $builder->build($node);

        $this->assertNotNull($tree);
        $this->assertArrayNotHasKey('atmospheric', $tree->getChildren());
    }

    public function testBuildThrowsWhenNodeHasEmptyDefaultButNonEmptyChildren(): void
    {
        $builder = new RecoatingTreeBuilder();
        $node = new RecoatingIntervalNodeDTO();
        $node->default = [$this->point(20, 240)];
        $node->branches['atmospheric'] = $this->branch([], ['ep' => $this->branch([$this->point(20, 120)])]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Серия по умолчанию.*atmospheric/');
        $builder->build($node);
    }

    private function point(int $temp, int $minutes): DryingTimePointDTO
    {
        $p = new DryingTimePointDTO();
        $p->temperature_at = $temp;
        $p->time_in_minutes = $minutes;
        $p->is_calculated = false;
        return $p;
    }

    /**
     * @param list<DryingTimePointDTO> $points
     * @param array<string, RecoatingIntervalNodeDTO> $branches
     */
    private function branch(array $points, array $branches = []): RecoatingIntervalNodeDTO
    {
        $node = new RecoatingIntervalNodeDTO();
        $node->default = $points;
        $node->branches = $branches;
        return $node;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./bin/phpunit app/tests/Unit/Coatings/Application/UseCase/Command/RecoatingTreeBuilderTest.php --colors=never 2>&1 | tail -15`
Expected: FAIL — `RecoatingTreeBuilder` ещё не существует.

- [ ] **Step 3: Implement `RecoatingTreeBuilder`**

Создать `app/src/Coatings/Application/UseCase/Command/RecoatingTreeBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command;

use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalNodeDTO;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Собирает RecoatingIntervalTree из RecoatingIntervalNodeDTO. Используется обоими
 * handler'ами создания/обновления покрытия.
 */
final readonly class RecoatingTreeBuilder
{
    /**
     * Рекурсивно собирает дерево интервалов из DTO.
     *
     * Возвращает null, если узел и все его потомки фактически пусты.
     * Бросает AppException, если у узла нет default-точек, но есть непустые потомки.
     */
    public function build(RecoatingIntervalNodeDTO $node, string $key = 'default'): ?RecoatingIntervalTree
    {
        $children = [];
        foreach ($node->branches as $childKey => $childDto) {
            $childTree = $this->build($childDto, (string) $childKey);
            if ($childTree !== null) {
                $children[] = $childTree;
            }
        }

        if ($node->default === [] && $children === []) {
            return null;
        }

        if ($node->default === []) {
            throw new AppException(sprintf(
                'Серия по умолчанию для узла "%s" не может быть пустой, если есть исключения.',
                $key,
            ));
        }

        $tree = new RecoatingIntervalTree($this->buildSeries($node->default), $key);
        foreach ($children as $childTree) {
            $tree = $tree->withChild($childTree);
        }
        return $tree;
    }

    /** @param list<DryingTimePointDTO> $points */
    private function buildSeries(array $points): DryingTimeSeries
    {
        $timePoints = array_map(
            fn(DryingTimePointDTO $p) => new TimeAtTemperature(
                $p->temperature_at,
                $p->time_in_minutes,
                $p->is_calculated,
            ),
            $points,
        );
        return new DryingTimeSeries(...$timePoints);
    }
}
```

- [ ] **Step 4: Run builder tests to verify pass**

Run: `./bin/phpunit app/tests/Unit/Coatings/Application/UseCase/Command/RecoatingTreeBuilderTest.php --colors=never 2>&1 | tail -10`
Expected: PASS (5 tests).

- [ ] **Step 5: Use the builder in `CreateCoatingCommandHandler`**

В `CreateCoatingCommandHandler.php`:
1. Удалить use-импорт `RecoatingIntervalTree` (если использовался только в этом блоке).
2. Добавить use:
   ```php
   use App\Coatings\Application\UseCase\Command\RecoatingTreeBuilder;
   use App\Shared\Infrastructure\Exception\AppException;
   ```
3. Изменить конструктор — добавить `private RecoatingTreeBuilder $treeBuilder` в `readonly`-properties (Symfony autowiring подхватит сам).
4. Заменить блок:
   ```php
               new RecoatingIntervalTree($this->buildDryingTimeSeries($dto->minRecoatingInterval)),
               $dto->maxRecoatingInterval !== null
                   ? new RecoatingIntervalTree($this->buildDryingTimeSeries($dto->maxRecoatingInterval))
                   : null,
   ```
   на:
   ```php
               $this->treeBuilder->build($dto->minRecoatingInterval)
                   ?? throw new AppException('Минимальный интервал перекрытия обязателен.'),
               $dto->maxRecoatingInterval !== null
                   ? $this->treeBuilder->build($dto->maxRecoatingInterval)
                   : null,
   ```
5. Если приватный метод `buildDryingTimeSeries` после этих изменений нигде больше не используется в файле — удалить.

- [ ] **Step 6: Use the builder in `UpdateCoatingCommandHandler`**

В `UpdateCoatingCommandHandler.php`:
1. Добавить use:
   ```php
   use App\Coatings\Application\UseCase\Command\RecoatingTreeBuilder;
   use App\Shared\Infrastructure\Exception\AppException;
   ```
2. Добавить в конструктор `private RecoatingTreeBuilder $treeBuilder`.
3. Заменить блок:
   ```php
           if (!empty($dto->minRecoatingInterval)) {
               $coating->setMinRecoatingInterval(
                   new RecoatingIntervalTree($this->buildDryingTimeSeries($dto->minRecoatingInterval)),
               );
           }
           $coating->setMaxRecoatingInterval(
               empty($dto->maxRecoatingInterval)
                   ? null
                   : new RecoatingIntervalTree($this->buildDryingTimeSeries($dto->maxRecoatingInterval)),
           );
   ```
   на:
   ```php
           $minTree = $this->treeBuilder->build($dto->minRecoatingInterval)
               ?? throw new AppException('Минимальный интервал перекрытия обязателен.');
           $coating->setMinRecoatingInterval($minTree);

           $coating->setMaxRecoatingInterval(
               $dto->maxRecoatingInterval !== null
                   ? $this->treeBuilder->build($dto->maxRecoatingInterval)
                   : null,
           );
   ```
4. Если приватный `buildDryingTimeSeries` стал неиспользуем — удалить. Если он используется ещё где-то (например, для `dryToTouch`/`fullCure`) — оставить.

- [ ] **Step 7: Run all unit tests to check no regressions**

Run: `./bin/phpunit app/tests/Unit --colors=never 2>&1 | tail -10`
Expected: всё OK.

- [ ] **Step 8: 🛑 Stop for user review/commit**

---

## Task 5: Twig — рекурсивный партиал + интеграция в форму

**Files:**
- Create: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_recoating_node.html.twig`
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/form.html.twig`

**Interfaces:**
- Consumes: nested-array из `CoatingMapper::buildInputDataFromDto` (Task 3).
- Produces: HTML с tabs/таблицами, имена полей соответствуют wire-формату из спеки.

- [ ] **Step 1: Create the partial**

Создать `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_recoating_node.html.twig`:

```twig
{# Параметры:
    - minNode (массив {default: {points: [...]}, branches: {...}}) — обязательно
    - maxNode (то же) — обязательно
    - level — 'root' | 'env' | 'base'
    - path — список ключей от корня до текущего узла, пустой для корня
    - envLabels — карта env-ключей в русские подписи (передаётся снаружи)
    - availableEnvKeys — список env-ключей, доступных к добавлению (только для root)
    - availableBaseKeys — список base-ключей, доступных к добавлению (только для env)
#}
{% from '/components/duration_input.html.twig' import duration_input %}

{% set isRoot = level == 'root' %}
{% set isEnv  = level == 'env' %}
{% set isBase = level == 'base' %}

{% set minPrefix = 'minRecoatingInterval' %}
{% set maxPrefix = 'maxRecoatingInterval' %}
{% for key in path %}
    {% set minPrefix = minPrefix ~ '[branches][' ~ key ~ ']' %}
    {% set maxPrefix = maxPrefix ~ '[branches][' ~ key ~ ']' %}
{% endfor %}
{% set minPrefix = minPrefix ~ '[default][points]' %}
{% set maxPrefix = maxPrefix ~ '[default][points]' %}

{% set minPoints = minNode.default.points ?? [] %}
{% set maxPoints = maxNode.default.points ?? [] %}
{% set rowCount = max(minPoints|length, maxPoints|length, 1) %}

{% set nodeId = (path|length > 0 ? path|join('-') : 'root') %}

{# Корень: контейнер с вкладками. Среды-вкладки внутри. #}
{% if isRoot %}
<div class="border rounded p-3" data-recoating-root>
    <ul class="nav nav-tabs mb-3" role="tablist" data-recoating-tabs>
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab"
                    data-bs-target="#recoating-pane-root" type="button" role="tab">
                Общее
            </button>
        </li>
        {% for envKey, envNode in (minNode.branches ?? {}) %}
            <li class="nav-item" role="presentation" data-env-tab="{{ envKey }}">
                <button class="nav-link" data-bs-toggle="tab"
                        data-bs-target="#recoating-pane-{{ envKey }}" type="button" role="tab">
                    {{ envLabels[envKey] ?? envKey }}
                    <button type="button" class="btn-close btn-close-sm ms-2"
                            data-action="click->coating-form#removeEnv"
                            data-coating-form-env-param="{{ envKey }}"
                            aria-label="Удалить ветку"></button>
                </button>
            </li>
        {% endfor %}
        <li class="nav-item dropdown ms-auto" data-recoating-add-env>
            <button class="nav-link dropdown-toggle text-success" data-bs-toggle="dropdown"
                    type="button" aria-expanded="false">
                + Среда
            </button>
            <ul class="dropdown-menu">
                {% for envKey in availableEnvKeys %}
                    <li>
                        <button type="button" class="dropdown-item"
                                data-action="click->coating-form#addEnv"
                                data-coating-form-env-param="{{ envKey }}"
                                data-env-option="{{ envKey }}">
                            {{ envLabels[envKey] ?? envKey }}
                        </button>
                    </li>
                {% endfor %}
            </ul>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="recoating-pane-root" role="tabpanel">
            {% include 'admin/coating/coating/_recoating_node.html.twig' with {
                minNode: minNode,
                maxNode: maxNode,
                level: 'env',
                path: [],
                envLabels: envLabels,
                showEnvControls: false,
                availableBaseKeys: availableBaseKeys,
            } only %}
        </div>
        {% for envKey, envMinNode in (minNode.branches ?? {}) %}
            {% set envMaxNode = (maxNode.branches ?? {})[envKey] ?? {default: {points: []}, branches: {}} %}
            <div class="tab-pane fade" id="recoating-pane-{{ envKey }}" role="tabpanel">
                {% include 'admin/coating/coating/_recoating_node.html.twig' with {
                    minNode: envMinNode,
                    maxNode: envMaxNode,
                    level: 'env',
                    path: [envKey],
                    envLabels: envLabels,
                    showEnvControls: true,
                    availableBaseKeys: availableBaseKeys,
                } only %}
            </div>
        {% endfor %}
    </div>
</div>

{# Уровни env и base — одинаковая таблица серии + (для env) список base-исключений. #}
{% else %}
<div class="{% if isBase %}border-start ps-3 mt-3{% endif %}" data-recoating-node="{{ nodeId }}">
    {% if isBase %}
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Основа: {{ path|last }}</h6>
            <button type="button" class="btn btn-sm btn-outline-danger"
                    data-action="click->coating-form#removeBase"
                    data-coating-form-env-param="{{ path[0] }}"
                    data-coating-form-base-param="{{ path|last }}">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    {% endif %}

    <table class="table table-sm align-middle mb-1">
        <thead>
            <tr>
                <th style="width: 160px;">Температура</th>
                <th>Минимальный</th>
                <th>Максимальный</th>
                <th style="width: 60px;"></th>
            </tr>
        </thead>
        <tbody data-series="recoating-{{ nodeId }}"
               data-min-prefix="{{ minPrefix }}"
               data-max-prefix="{{ maxPrefix }}">
            {% for i in 0..(rowCount - 1) %}
                {% set minRow = minPoints[i] ?? {temperature_at: 20, days: 0, hours: 0, minutes: 0} %}
                {% set maxRow = maxPoints[i] ?? {} %}
                {% set temp = minRow.temperature_at ?? maxRow.temperature_at ?? 20 %}
                <tr>
                    <td>
                        <div class="input-group input-group-sm">
                            <input type="number"
                                   name="{{ minPrefix }}[{{ i }}][temperature_at]"
                                   value="{{ temp }}"
                                   class="form-control"
                                   data-row="{{ nodeId }}-{{ i }}"
                                   data-action="input->coating-form#syncTemperature"
                                   min="-50" max="100" required>
                            <span class="input-group-text">°C</span>
                        </div>
                        <input type="hidden" name="{{ maxPrefix }}[{{ i }}][temperature_at]"
                               value="{{ temp }}" data-temp-mirror="{{ nodeId }}-{{ i }}">
                    </td>
                    <td>{{ duration_input(minPrefix ~ '[' ~ i ~ ']', minRow, true, 'Минимальный интервал перекрытия при +' ~ temp ~ '°C') }}</td>
                    <td>{{ duration_input(maxPrefix ~ '[' ~ i ~ ']', maxRow, false, 'Максимальный интервал перекрытия при +' ~ temp ~ '°C') }}</td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger"
                                data-action="click->coating-form#removeRow"
                                title="Удалить точку">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            {% endfor %}
        </tbody>
    </table>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary"
                data-action="click->coating-form#addRow"
                data-coating-form-tbody-param="recoating-{{ nodeId }}">
            + Точка
        </button>

        {% if isEnv and (showEnvControls ?? true) %}
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-success dropdown-toggle"
                        data-bs-toggle="dropdown" type="button">
                    + Исключение для основы ЛКМ
                </button>
                <ul class="dropdown-menu">
                    {% for baseKey in availableBaseKeys %}
                        <li>
                            <button type="button" class="dropdown-item"
                                    data-action="click->coating-form#addBase"
                                    data-coating-form-env-param="{{ path[0] }}"
                                    data-coating-form-base-param="{{ baseKey }}">
                                {{ baseKey }}
                            </button>
                        </li>
                    {% endfor %}
                </ul>
            </div>
        {% endif %}
    </div>

    {% if isEnv %}
        {% for baseKey, baseMinNode in (minNode.branches ?? {}) %}
            {% set baseMaxNode = (maxNode.branches ?? {})[baseKey] ?? {default: {points: []}, branches: {}} %}
            {% include 'admin/coating/coating/_recoating_node.html.twig' with {
                minNode: baseMinNode,
                maxNode: baseMaxNode,
                level: 'base',
                path: path|merge([baseKey]),
                envLabels: envLabels,
                availableBaseKeys: [],
            } only %}
        {% endfor %}
    {% endif %}
</div>
{% endif %}
```

- [ ] **Step 2: Wire partial into `form.html.twig`**

В `form.html.twig` найти блок «Интервал перекрытия» (между комментарием `{# ─── Интервал перекрытия ─── #}` и закрывающим `</div>` секции «Время высыхания»). Это строки ~289-348.

Заменить весь блок (от `<div class="border rounded p-3">` с «Интервал перекрытия» до его закрывающего `</div>`) на:

```twig
                    {# ─── Интервал перекрытия (дерево: общее → среда → основа ЛКМ) ─── #}
                    {% set envLabels = {
                        atmospheric: 'Атмосферная',
                        immersion:   'Погружение',
                        special:     'Спец среды',
                    } %}
                    {% set defaultRecoatingNode = {default: {points: [{temperature_at: 20, days: 0, hours: 0, minutes: 0}]}, branches: {}} %}
                    {% set minRecoatingNode = inputData.minRecoatingInterval ?? defaultRecoatingNode %}
                    {% set maxRecoatingNode = inputData.maxRecoatingInterval ?? {default: {points: []}, branches: {}} %}
                    {% set usedEnvKeys = (minRecoatingNode.branches ?? {})|keys %}
                    {% set availableEnvKeys = ['atmospheric', 'immersion', 'special']|filter(k => k not in usedEnvKeys) %}
                    {% set availableBaseKeys = coatingBases|map(b => b.value) %}

                    {% include 'admin/coating/coating/_recoating_node.html.twig' with {
                        minNode: minRecoatingNode,
                        maxNode: maxRecoatingNode,
                        level: 'root',
                        path: [],
                        envLabels: envLabels,
                        availableEnvKeys: availableEnvKeys,
                        availableBaseKeys: availableBaseKeys,
                    } only %}
```

(Заголовок «Интервал перекрытия» и обёрточный `border rounded p-3` уходят внутрь партиала.)

- [ ] **Step 3: Open browser and verify it renders without crash**

Запустить локальный dev-сервер (через `symfony serve -d` или существующий `docker compose up`), открыть `/cabinet/coating/coating/list` и зайти на редактирование любого покрытия. Глазами:
- Видна секция «Интервал перекрытия» с вкладкой «Общее» и кнопкой «+ Среда».
- На вкладке «Общее» — таблица «Темп / Min / Max».
- Никаких ошибок в консоли.

(Stimulus-actions ещё не работают для add/remove env/base — это Task 6. Но статический рендер должен быть валидным.)

- [ ] **Step 4: 🛑 Stop for user review/commit**

---

## Task 6: Stimulus — `addEnv/removeEnv/addBase/removeBase` + DOM-based prefix

**Files:**
- Modify: `app/assets/controllers/coating_form_controller.js`

**Interfaces:**
- Consumes: HTML-разметка из Task 5 (data-атрибуты `data-recoating-root`, `data-recoating-tabs`, `data-recoating-add-env`, `data-env-tab`, `data-env-option`, `data-min-prefix`, `data-max-prefix`, `data-recoating-node`).
- Produces: рабочий редактор интервалов с динамическим добавлением/удалением сред и оснований.

Старая логика `_seriesPrefixOf` (regex по имени поля) ломается на nested-формате. Заменим её на DOM-based: подниматься до `tbody[data-series]` и читать `data-min-prefix`/`data-max-prefix`.

- [ ] **Step 1: Replace regex-based prefix resolution with DOM walk**

В `coating_form_controller.js` заменить метод `_seriesPrefixOf`:

```javascript
    /** Возвращает базовое имя серии для строки (по data-атрибутам tbody). */
    _seriesPrefixFromRow(tr) {
        const tbody = tr?.closest('tbody[data-series]');
        if (!tbody) return null;
        return {
            min: tbody.dataset.minPrefix || null,
            max: tbody.dataset.maxPrefix || null,
        };
    }
```

В `_handleModalShow` найти строку:
```javascript
                ? this._gatherSiblingPoints(tr, this._seriesPrefixOf(this.currentName)).length < 2
```
Заменить на:
```javascript
                ? this._gatherSiblingPoints(tr).length < 2
```

В `calculateDuration` найти:
```javascript
        const points = this._gatherSiblingPoints(tr, this._seriesPrefixOf(this.currentName));
```
Заменить на:
```javascript
        const points = this._gatherSiblingPoints(tr);
```

- [ ] **Step 2: Update `_gatherSiblingPoints` to use DOM walk**

Заменить тело метода `_gatherSiblingPoints` целиком:

```javascript
    _gatherSiblingPoints(currentTr) {
        const tbody = currentTr?.closest('tbody[data-series]');
        if (!tbody) return [];
        // Берём только префикс той серии, к которой принадлежит currentTr.
        // Кнопки Min и Max лежат в одной tr, у каждой свой data-target-name с правильным префиксом.
        const minPrefix = tbody.dataset.minPrefix;
        const maxPrefix = tbody.dataset.maxPrefix;
        const seriesPrefix = this.currentName?.startsWith(maxPrefix) ? maxPrefix : minPrefix;
        if (!seriesPrefix) return [];

        const points = [];
        for (const otherTr of tbody.querySelectorAll('tr')) {
            if (otherTr === currentTr) continue;
            const tempInput = otherTr.querySelector(`input[name^="${seriesPrefix}["][name$="[temperature_at]"]`);
            if (!tempInput) continue;
            const probe = otherTr.querySelector(`input[type=hidden][name^="${seriesPrefix}["][name$="[days]"]`);
            if (!probe) continue;
            const base = probe.name.replace(/\[days\]$/, '');
            const d = parseInt(otherTr.querySelector(`input[type=hidden][name="${base}[days]"]`)?.value || 0, 10);
            const h = parseInt(otherTr.querySelector(`input[type=hidden][name="${base}[hours]"]`)?.value || 0, 10);
            const m = parseInt(otherTr.querySelector(`input[type=hidden][name="${base}[minutes]"]`)?.value || 0, 10);
            const total = d * 1440 + h * 60 + m;
            if (total === 0) continue;
            points.push({ temperature_at: parseInt(tempInput.value || 0, 10), minutes: total });
        }
        return points;
    }
```

- [ ] **Step 3: Update `addRow` to use tbody param**

Заменить `addRow`:

```javascript
    addRow(event) {
        const series = event.params?.tbody ?? event.currentTarget.dataset.series;
        if (!series) return;
        const tbody = this.element.querySelector(`tbody[data-series="${series}"]`);
        if (!tbody) return;
        const i = tbody.children.length;
        const tr = document.createElement('tr');

        if (series.startsWith('recoating-')) {
            const minPrefix = tbody.dataset.minPrefix;
            const maxPrefix = tbody.dataset.maxPrefix;
            const nodeId = series.replace(/^recoating-/, '');
            tr.innerHTML = this._pairedRecoatingRow(minPrefix, maxPrefix, nodeId, i);
        } else {
            tr.innerHTML = this._singleSeriesRow(series, i);
        }
        tbody.appendChild(tr);
    }
```

- [ ] **Step 4: Replace `_pairedRecoatingRow` signature**

Заменить целиком метод `_pairedRecoatingRow`:

```javascript
    _pairedRecoatingRow(minPrefix, maxPrefix, nodeId, i) {
        const minBase = `${minPrefix}[${i}]`;
        const maxBase = `${maxPrefix}[${i}]`;
        const rowId = `${nodeId}-${i}`;
        return `
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" name="${minBase}[temperature_at]" value="20"
                           class="form-control" data-row="${rowId}"
                           data-action="input->coating-form#syncTemperature"
                           min="-50" max="100" required>
                    <span class="input-group-text">°C</span>
                </div>
                <input type="hidden" name="${maxBase}[temperature_at]" value="20" data-temp-mirror="${rowId}">
            </td>
            <td>
                <input type="hidden" name="${minBase}[days]" value="0">
                <input type="hidden" name="${minBase}[hours]" value="0">
                <input type="hidden" name="${minBase}[minutes]" value="0">
                <button type="button" class="btn btn-sm duration-display-btn btn-outline-secondary text-muted"
                        data-bs-toggle="modal" data-bs-target="#durationModal"
                        data-target-name="${minBase}"
                        data-target-label="Минимальный интервал перекрытия при +20°C"
                        data-required="1">
                    <i class="bi bi-pencil"></i> не задано
                </button>
            </td>
            <td>
                <input type="hidden" name="${maxBase}[days]" value="0">
                <input type="hidden" name="${maxBase}[hours]" value="0">
                <input type="hidden" name="${maxBase}[minutes]" value="0">
                <button type="button" class="btn btn-sm duration-display-btn btn-outline-secondary text-muted"
                        data-bs-toggle="modal" data-bs-target="#durationModal"
                        data-target-name="${maxBase}"
                        data-target-label="Максимальный интервал перекрытия при +20°C"
                        data-required="0">
                    <i class="bi bi-pencil"></i> без ограничения
                </button>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger"
                        data-action="click->coating-form#removeRow" title="Удалить точку">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
    }
```

В `_rowHTML` (метод-роутер) — старый код `if (series === 'recoatingInterval')` больше не нужен; этот case теперь в `addRow`. Метод можно удалить.

- [ ] **Step 5: Add `addEnv` and `removeEnv` actions**

Добавить в класс:

```javascript
    addEnv(event) {
        const envKey = event.params.env;
        if (!envKey) return;
        const root = this.element.querySelector('[data-recoating-root]');
        if (!root) return;

        const tabsUl = root.querySelector('[data-recoating-tabs]');
        const tabContent = root.querySelector('.tab-content');

        const envLabels = { atmospheric: 'Атмосферная', immersion: 'Погружение', special: 'Спец среды' };
        const label = envLabels[envKey] || envKey;
        const nodeId = envKey;

        // Создать tab-кнопку.
        const tabLi = document.createElement('li');
        tabLi.className = 'nav-item';
        tabLi.setAttribute('role', 'presentation');
        tabLi.setAttribute('data-env-tab', envKey);
        tabLi.innerHTML = `
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#recoating-pane-${envKey}"
                    type="button" role="tab">
                ${label}
                <button type="button" class="btn-close btn-close-sm ms-2"
                        data-action="click->coating-form#removeEnv"
                        data-coating-form-env-param="${envKey}"
                        aria-label="Удалить ветку"></button>
            </button>`;
        const addEnvLi = root.querySelector('[data-recoating-add-env]');
        tabsUl.insertBefore(tabLi, addEnvLi);

        // Создать pane.
        const paneDiv = document.createElement('div');
        paneDiv.className = 'tab-pane fade';
        paneDiv.id = `recoating-pane-${envKey}`;
        paneDiv.setAttribute('role', 'tabpanel');
        paneDiv.innerHTML = this._envPaneHTML(envKey);
        tabContent.appendChild(paneDiv);

        // Скрыть выбранную среду в dropdown.
        const opt = root.querySelector(`[data-env-option="${envKey}"]`);
        if (opt) opt.closest('li').style.display = 'none';

        // Активировать новую вкладку через Bootstrap.
        const Tab = window.bootstrap?.Tab;
        if (Tab) new Tab(tabLi.querySelector('button.nav-link')).show();
    }

    removeEnv(event) {
        event.stopPropagation();
        const envKey = event.params.env;
        if (!envKey) return;
        const root = this.element.querySelector('[data-recoating-root]');
        if (!root) return;
        const tabLi = root.querySelector(`[data-env-tab="${envKey}"]`);
        const pane = root.querySelector(`#recoating-pane-${envKey}`);
        const wasActive = tabLi?.querySelector('.nav-link.active');
        tabLi?.remove();
        pane?.remove();

        // Вернуть среду в dropdown.
        const opt = root.querySelector(`[data-env-option="${envKey}"]`);
        if (opt) opt.closest('li').style.display = '';

        // Если удалили активную — переключиться на «Общее».
        if (wasActive) {
            const Tab = window.bootstrap?.Tab;
            const rootBtn = root.querySelector('button[data-bs-target="#recoating-pane-root"]');
            if (Tab && rootBtn) new Tab(rootBtn).show();
        }
    }

    /** Генерирует HTML панели для свежей env-ветки (одна точка по умолчанию, без base-исключений). */
    _envPaneHTML(envKey) {
        const minPrefix = `minRecoatingInterval[branches][${envKey}][default][points]`;
        const maxPrefix = `maxRecoatingInterval[branches][${envKey}][default][points]`;
        const nodeId = envKey;
        const rowHTML = this._pairedRecoatingRow(minPrefix, maxPrefix, nodeId, 0);
        const availableBases = this._availableBaseKeysFromRoot();
        const baseDropdown = `
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-success dropdown-toggle"
                        data-bs-toggle="dropdown" type="button">
                    + Исключение для основы ЛКМ
                </button>
                <ul class="dropdown-menu">
                    ${availableBases.map(b => `
                        <li><button type="button" class="dropdown-item"
                                    data-action="click->coating-form#addBase"
                                    data-coating-form-env-param="${envKey}"
                                    data-coating-form-base-param="${b}">${b}</button></li>
                    `).join('')}
                </ul>
            </div>`;
        return `
            <div data-recoating-node="${nodeId}">
                <table class="table table-sm align-middle mb-1">
                    <thead><tr><th style="width: 160px;">Температура</th><th>Минимальный</th><th>Максимальный</th><th style="width: 60px;"></th></tr></thead>
                    <tbody data-series="recoating-${nodeId}" data-min-prefix="${minPrefix}" data-max-prefix="${maxPrefix}">
                        <tr>${rowHTML}</tr>
                    </tbody>
                </table>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            data-action="click->coating-form#addRow"
                            data-coating-form-tbody-param="recoating-${nodeId}">+ Точка</button>
                    ${baseDropdown}
                </div>
            </div>`;
    }

    _availableBaseKeysFromRoot() {
        // Берём список оснований из любого env-уровневого dropdown'а на странице.
        const items = this.element.querySelectorAll('[data-coating-form-base-param]');
        const set = new Set();
        items.forEach(i => set.add(i.dataset.coatingFormBaseParam));
        return [...set];
    }
```

- [ ] **Step 6: Add `addBase` and `removeBase` actions**

```javascript
    addBase(event) {
        const envKey = event.params.env;
        const baseKey = event.params.base;
        if (!envKey || !baseKey) return;
        const pane = this.element.querySelector(`#recoating-pane-${envKey} [data-recoating-node]`);
        if (!pane) return;

        // Проверим, что эту основу ещё не добавили.
        const existing = pane.parentElement.querySelector(`[data-recoating-node="${envKey}-${baseKey}"]`);
        if (existing) return;

        const minPrefix = `minRecoatingInterval[branches][${envKey}][branches][${baseKey}][default][points]`;
        const maxPrefix = `maxRecoatingInterval[branches][${envKey}][branches][${baseKey}][default][points]`;
        const nodeId = `${envKey}-${baseKey}`;
        const rowHTML = this._pairedRecoatingRow(minPrefix, maxPrefix, nodeId, 0);

        const block = document.createElement('div');
        block.className = 'border-start ps-3 mt-3';
        block.setAttribute('data-recoating-node', nodeId);
        block.innerHTML = `
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0">Основа: ${baseKey}</h6>
                <button type="button" class="btn btn-sm btn-outline-danger"
                        data-action="click->coating-form#removeBase"
                        data-coating-form-env-param="${envKey}"
                        data-coating-form-base-param="${baseKey}">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <table class="table table-sm align-middle mb-1">
                <thead><tr><th style="width: 160px;">Температура</th><th>Минимальный</th><th>Максимальный</th><th style="width: 60px;"></th></tr></thead>
                <tbody data-series="recoating-${nodeId}" data-min-prefix="${minPrefix}" data-max-prefix="${maxPrefix}">
                    <tr>${rowHTML}</tr>
                </tbody>
            </table>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        data-action="click->coating-form#addRow"
                        data-coating-form-tbody-param="recoating-${nodeId}">+ Точка</button>
            </div>`;
        // Вставляем сразу после контейнера-родителя env-pane (после кнопок).
        pane.parentElement.appendChild(block);
    }

    removeBase(event) {
        const envKey = event.params.env;
        const baseKey = event.params.base;
        if (!envKey || !baseKey) return;
        const block = this.element.querySelector(`[data-recoating-node="${envKey}-${baseKey}"]`);
        block?.remove();
    }
```

- [ ] **Step 7: Manual smoke-test**

Перезапустить ассеты (`npm run dev` или эквивалент) и пройти сценарий в браузере:
1. Открыть форму редактирования любого покрытия.
2. Вкладка «Общее» работает: можно добавить точку, открыть модалку длительности, сохранить.
3. Кликнуть «+ Среда → Атмосферная» — появилась вкладка, активировалась автоматически, внутри одна строка с +20°C.
4. Внутри атмосферной — «+ Исключение для основы ЛКМ → EP» — появился блок основы.
5. Удалить блок основы (крестик) — пропал.
6. Удалить вкладку «Атмосферная» (крестик внутри таба) — вкладка пропала, активной стала «Общее».
7. Сабмит формы со сценарием default + atmospheric/EP исключение — проверить через `dd($request->getPayload()->all())` в контроллере или через DevTools (Form Data), что имена полей строго `minRecoatingInterval[branches][atmospheric][branches][EP][default][points][0][temperature_at]` и т.п.

- [ ] **Step 8: 🛑 Stop for user review/commit**

---

## Task 7: Functional round-trip test

**Files:**
- Create: `app/tests/Functional/Coatings/Infrastructure/Controller/Coating/UpdateActionRecoatingTreeTest.php`

**Interfaces:**
- Consumes: всю цепочку из Task 1-6.

- [ ] **Step 1: Discover existing functional test scaffolding**

Прочитать `app/tests/Functional/` — найти базовый `WebTestCase`-наследник, фикстуры покрытий/производителей, авторизацию пользователя. Если их нет — наследоваться от `Symfony\Bundle\FrameworkBundle\Test\WebTestCase` напрямую и подготовить фикстуру в самом тесте.

Run: `find app/tests/Functional -type f -name '*.php' | head -10`

- [ ] **Step 2: Write happy-path test**

Создать `app/tests/Functional/Coatings/Infrastructure/Controller/Coating/UpdateActionRecoatingTreeTest.php`. Шаблон:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UpdateActionRecoatingTreeTest extends WebTestCase
{
    public function testSubmittingTreeWithBranchPersistsExceptionAndIsAccessibleViaLookup(): void
    {
        $client = static::createClient();
        // TODO: загрузить фикстуру: одно покрытие с плоским min/maxRecoating. Получить $coatingId.
        $coatingId = $this->prepareCoating($client);

        $client->request('POST', "/cabinet/coating/coating/{$coatingId}/edit", [
            // Поля, валидируемые getValidationCollectionCoating (минимально достаточные).
            'title' => 'Updated', 'description' => 'updated desc',
            'volumeSolid' => 50, 'massDensity' => 1.2, 'base' => 'EP',
            'minDft' => 80, 'maxDft' => 150, 'tdsDft' => 100,
            'applicationMinTemp' => 5,
            'dryToTouch' => [['temperature_at' => 20, 'days' => 0, 'hours' => 1, 'minutes' => 0]],
            'fullCure'   => [['temperature_at' => 20, 'days' => 1, 'hours' => 0, 'minutes' => 0]],
            'pack' => 1.0,
            'manufacturer' => ['id' => $this->getManufacturerId()],
            'minRecoatingInterval' => [
                'default' => ['points' => [['temperature_at' => 20, 'days' => 0, 'hours' => 4, 'minutes' => 0]]],
                'branches' => [
                    'atmospheric' => [
                        'default' => ['points' => [['temperature_at' => 20, 'days' => 0, 'hours' => 3, 'minutes' => 0]]],
                        'branches' => [
                            'ep' => [
                                'default' => ['points' => [['temperature_at' => 20, 'days' => 0, 'hours' => 2, 'minutes' => 0]]],
                                'branches' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'maxRecoatingInterval' => ['default' => ['points' => []], 'branches' => []],
        ]);

        $this->assertResponseRedirects();

        /** @var CoatingRepositoryInterface $repo */
        $repo = static::getContainer()->get(CoatingRepositoryInterface::class);
        $coating = $repo->findOneById($coatingId);

        $epSeries = $coating->minRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP);
        $this->assertSame(120, $epSeries->points[0]->timeInMinutes); // 2 часа

        $immersionSeries = $coating->minRecoatingFor(EnvironmentType::Immersion, CoatingBase::EP);
        $this->assertSame(240, $immersionSeries->points[0]->timeInMinutes); // фолбэк на root default — 4 часа
    }

    private function prepareCoating($client): string
    {
        // TODO: реализовать загрузку фикстуры или создание coating'а через хендлер.
        // Если в проекте уже есть универсальная фикстура — переиспользовать.
        throw new \RuntimeException('Implement prepareCoating: insert a Coating + Manufacturer and return UUID.');
    }

    private function getManufacturerId(): string
    {
        throw new \RuntimeException('Implement getManufacturerId.');
    }
}
```

- [ ] **Step 3: Implement fixture setup**

Изучить существующие functional-тесты (если есть) и:
- Если есть базовый класс `WebTestCase` с готовой авторизацией и фикстурами — наследоваться от него.
- Иначе — создать минимальную фикстуру через `EntityManager` прямо в `setUp()` теста: один `Manufacturer`, одно `Coating` с дефолтным flat-min/max.

Если в проекте нет HTTP-авторизации (или тесты идут без неё) — пропустить login. Если есть — заавторизовать тестового пользователя через `loginUser()` хелпер.

- [ ] **Step 4: Run the test**

Run: `./bin/phpunit app/tests/Functional/Coatings/Infrastructure/Controller/Coating/UpdateActionRecoatingTreeTest.php --colors=never 2>&1 | tail -30`
Expected: PASS.

Если падает по причинам, не связанным с деревом (фикстуры/авторизация/роутинг) — править фикстуру; основная проверка на дерево должна оставаться.

- [ ] **Step 5: Run full test suite**

Run: `./bin/phpunit --colors=never 2>&1 | tail -10`
Expected: всё OK.

- [ ] **Step 6: 🛑 Stop for user review/commit**

---

## Self-review

После написания плана прошёлся по спеке:

- **UX** — Tasks 5, 6 покрывают вкладки, добавление/удаление сред и основ, симметрию min/max.
- **Wire-формат** — Tasks 3 (mapper), 5 (twig prefixes), 6 (stimulus prefixes) согласованы — везде `[default][points]` и `[branches][envKey][...]`.
- **DTO** — Tasks 1, 2 создают и встраивают `RecoatingIntervalNodeDTO`.
- **Серверная сборка** — Task 4 (handlers).
- **Twig** — Task 5.
- **Stimulus** — Task 6.
- **Валидация** — Task 3 включает `recoatingNodeConstraints()`.
- **Тесты** — Tasks 2, 3, 7 покрывают transformer, mapper, end-to-end. Доменные тесты от рефакторинга не страдают.

**Placeholder scan:** в Task 7 явные TODO в `prepareCoating`/`getManufacturerId` — это сознательно, потому что я не знаю конкретики фикстурного слоя проекта. Это указано в Step 3 как часть работы по задаче.

**Type consistency:** имена методов одинаковые в Task 4 для обоих хендлеров (`buildRecoatingTree`, `requireTree`). Названия путей `[branches]` и `[default][points]` идентичны в Tasks 3, 5, 6.

Если эта задача исполняется агентом — он должен прочитать `docs/superpowers/specs/2026-06-22-coating-recoating-tree-ui-design.md` перед стартом Task 1.
