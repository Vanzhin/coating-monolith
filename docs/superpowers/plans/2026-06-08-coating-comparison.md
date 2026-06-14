# Coating Comparison Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Side-by-side сравнение 2–4 покрытий через корзину в Symfony-сессии, со страницей сравнения, повторяющей структуру превью-модалки, и подсветкой различающихся строк.

**Architecture:** Корзина — `list<string>` в session под одним ключом, обёрнутая в типизированный `ComparisonBasket`. Diff считает `ObjectDiffService` (domain-agnostic, конфиг-driven по `get_object_vars`), который вызывает `ComparisonDiffService` с правилами специфичными для `CoatingDTO`. UI — sticky-бар внизу каждой страницы кабинета + отдельная страница `/cabinet/coating/comparison`. Все мутации — POST + CSRF.

**Tech Stack:** PHP 8.3, Symfony 6.x, Doctrine ORM 3.1, Bootstrap 5, Twig. Тесты — PHPUnit.

> **Важно: коммиты не делаем.** Пользователь коммитит сам после своих правок. Все «git commit» шаги пропускаем.

---

## Файловая структура

**Создаются:**

```
app/src/Shared/Application/Service/ObjectDiffService.php
app/src/Coatings/Application/Service/ComparisonBasket.php
app/src/Coatings/Application/Service/ComparisonDiffService.php
app/src/Coatings/Application/Service/Exception/BasketFullException.php
app/src/Coatings/Infrastructure/Controller/Comparison/ShowAction.php
app/src/Coatings/Infrastructure/Controller/Comparison/AddAction.php
app/src/Coatings/Infrastructure/Controller/Comparison/RemoveAction.php
app/src/Coatings/Infrastructure/Controller/Comparison/ClearAction.php
app/src/Shared/Infrastructure/Twig/Extension/ComparisonExtension.php
app/src/Shared/Infrastructure/Templates/admin/coating/coating/compare.html.twig
app/src/Shared/Infrastructure/Templates/components/comparison_bar.html.twig
app/tests/Unit/Shared/Application/Service/ObjectDiffServiceTest.php
app/tests/Unit/Coatings/Application/Service/ComparisonBasketTest.php
app/tests/Unit/Coatings/Application/Service/ComparisonDiffServiceTest.php
```

**Модифицируются:**

```
app/src/Shared/Infrastructure/Templates/cabinet/index.html.twig
app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig
```

---

## Task 1: ObjectDiffService

Generic-сервис, сравнивает значения публичных свойств одного класса между N объектами. Возвращает `array<string, bool>`.

**Files:**
- Create: `app/src/Shared/Application/Service/ObjectDiffService.php`
- Test: `app/tests/Unit/Shared/Application/Service/ObjectDiffServiceTest.php`

- [ ] **Step 1: Создать тест с фиктивным классом-fixture**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Service;

use App\Shared\Application\Service\ObjectDiffService;
use PHPUnit\Framework\TestCase;

final class ObjectDiffServiceTest extends TestCase
{
    private ObjectDiffService $service;

    protected function setUp(): void
    {
        $this->service = new ObjectDiffService();
    }

    public function testEmptyListReturnsEmptyMap(): void
    {
        $this->assertSame([], $this->service->diff([]));
    }

    public function testSingleObjectAllFalse(): void
    {
        $obj = $this->fixture(['a' => 1, 'b' => 'x']);
        $this->assertSame(['a' => false, 'b' => false], $this->service->diff([$obj]));
    }

    public function testTwoIdenticalObjectsAllFalse(): void
    {
        $a = $this->fixture(['a' => 1, 'b' => 'x']);
        $b = $this->fixture(['a' => 1, 'b' => 'x']);
        $this->assertSame(['a' => false, 'b' => false], $this->service->diff([$a, $b]));
    }

    public function testFieldDiffersMarkedTrue(): void
    {
        $a = $this->fixture(['a' => 1, 'b' => 'x']);
        $b = $this->fixture(['a' => 2, 'b' => 'x']);
        $result = $this->service->diff([$a, $b]);
        $this->assertTrue($result['a']);
        $this->assertFalse($result['b']);
    }

    public function testSkipExcludesField(): void
    {
        $a = $this->fixture(['a' => 1, 'b' => 'x']);
        $b = $this->fixture(['a' => 2, 'b' => 'y']);
        $result = $this->service->diff([$a, $b], skip: ['a']);
        $this->assertArrayNotHasKey('a', $result);
        $this->assertTrue($result['b']);
    }

    public function testOnlyOverridesDefault(): void
    {
        $a = $this->fixture(['a' => 1, 'b' => 'x']);
        $b = $this->fixture(['a' => 2, 'b' => 'y']);
        $result = $this->service->diff([$a, $b], only: ['a']);
        $this->assertSame(['a' => true], $result);
    }

    public function testNormalizerOverridesComparison(): void
    {
        $a = $this->fixture(['a' => 1, 'b' => 'X']);
        $b = $this->fixture(['a' => 1, 'b' => 'x']);
        $result = $this->service->diff(
            [$a, $b],
            normalizers: ['b' => fn(string $v) => strtolower($v)],
        );
        $this->assertFalse($result['b']);
    }

    public function testNullVsZeroAreDifferent(): void
    {
        $a = $this->fixture(['a' => 0, 'b' => 'x']);
        $b = $this->fixture(['a' => null, 'b' => 'x']);
        $result = $this->service->diff([$a, $b]);
        $this->assertTrue($result['a']);
    }

    public function testFieldOrderFromFirstObject(): void
    {
        $a = $this->fixture(['a' => 1, 'b' => 'x']);
        $b = $this->fixture(['a' => 1, 'b' => 'x']);
        $result = $this->service->diff([$a, $b]);
        $this->assertSame(['a', 'b'], array_keys($result));
    }

    /** @param array<string, mixed> $values */
    private function fixture(array $values): object
    {
        $obj = new class {
            public mixed $a = null;
            public mixed $b = null;
        };
        foreach ($values as $k => $v) {
            $obj->$k = $v;
        }
        return $obj;
    }
}
```

- [ ] **Step 2: Запустить тест — должен упасть с "Class ObjectDiffService not found"**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith && \
docker-compose exec -T manager_php-fpm vendor/bin/phpunit tests/Unit/Shared/Application/Service/ObjectDiffServiceTest.php
```

Ожидаемо: ошибка автозагрузки.

- [ ] **Step 3: Создать сервис**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Application\Service;

final class ObjectDiffService
{
    /**
     * Сравнивает значения свойств между объектами. Возвращает мапу
     * «имя поля → есть ли различие». Дефолтно сравнивает все public-свойства
     * первого объекта; список можно сузить через $only или $skip; для отдельных
     * полей можно задать кастомный нормализатор значения через $normalizers.
     *
     * @template T of object
     * @param list<T> $objects
     * @param list<string>|null $only         null = все public-свойства первого объекта
     * @param list<string> $skip              игнорируется, если $only задан
     * @param array<string, callable(mixed): string> $normalizers
     * @return array<string, bool>
     */
    public function diff(
        array $objects,
        ?array $only = null,
        array $skip = [],
        array $normalizers = [],
    ): array {
        if ($objects === []) {
            return [];
        }

        $fields = $only ?? array_values(array_diff(
            array_keys(get_object_vars($objects[0])),
            $skip,
        ));

        $markers = [];
        foreach ($fields as $field) {
            $sigs = array_map(
                fn(object $o) => isset($normalizers[$field])
                    ? ($normalizers[$field])($o->$field ?? null)
                    : serialize($o->$field ?? null),
                $objects,
            );
            $markers[$field] = count(array_unique($sigs)) > 1;
        }

        return $markers;
    }
}
```

- [ ] **Step 4: Запустить тест — должен пройти**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith && \
docker-compose exec -T manager_php-fpm vendor/bin/phpunit tests/Unit/Shared/Application/Service/ObjectDiffServiceTest.php
```

Ожидаемо: `OK (9 tests, ...)`

---

## Task 2: ComparisonDiffService

Тонкая обёртка вокруг `ObjectDiffService` со спец-правилами для `CoatingDTO`: skip-list (`id`, `title`, `description`), нормализаторы (`tags`, `manufacturer`, `thinner`).

**Files:**
- Create: `app/src/Coatings/Application/Service/ComparisonDiffService.php`
- Test: `app/tests/Unit/Coatings/Application/Service/ComparisonDiffServiceTest.php`

- [ ] **Step 1: Создать тест**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\Service;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTO;
use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use App\Coatings\Application\Service\ComparisonDiffService;
use App\Shared\Application\Service\ObjectDiffService;
use PHPUnit\Framework\TestCase;

final class ComparisonDiffServiceTest extends TestCase
{
    private ComparisonDiffService $service;

    protected function setUp(): void
    {
        $this->service = new ComparisonDiffService(new ObjectDiffService());
    }

    public function testEmptyList(): void
    {
        $this->assertSame([], $this->service->computeDiffMarkers([]));
    }

    public function testIdentical(): void
    {
        $a = $this->dto();
        $b = $this->dto();
        foreach ($this->service->computeDiffMarkers([$a, $b]) as $key => $isDiff) {
            $this->assertFalse($isDiff, sprintf('Field %s should be equal', $key));
        }
    }

    public function testVolumeSolidDiffers(): void
    {
        $a = $this->dto();
        $b = $this->dto(['volumeSolid' => 60]);
        $result = $this->service->computeDiffMarkers([$a, $b]);
        $this->assertTrue($result['volumeSolid']);
        $this->assertFalse($result['massDensity']);
    }

    public function testIdSkipped(): void
    {
        $a = $this->dto(['id' => 'aaa']);
        $b = $this->dto(['id' => 'bbb']);
        $result = $this->service->computeDiffMarkers([$a, $b]);
        $this->assertArrayNotHasKey('id', $result);
    }

    public function testTitleSkipped(): void
    {
        $a = $this->dto(['title' => 'X']);
        $b = $this->dto(['title' => 'Y']);
        $result = $this->service->computeDiffMarkers([$a, $b]);
        $this->assertArrayNotHasKey('title', $result);
    }

    public function testDescriptionSkipped(): void
    {
        $a = $this->dto(['description' => 'aaaa']);
        $b = $this->dto(['description' => 'bbbb']);
        $result = $this->service->computeDiffMarkers([$a, $b]);
        $this->assertArrayNotHasKey('description', $result);
    }

    public function testThinnerNullVsEmptyStringSame(): void
    {
        $a = $this->dto(['thinner' => null]);
        $b = $this->dto(['thinner' => '']);
        $result = $this->service->computeDiffMarkers([$a, $b]);
        $this->assertFalse($result['thinner']);
    }

    public function testManufacturerComparedById(): void
    {
        $a = $this->dto(['manufacturer' => $this->manufacturer('m-1', 'Производитель А')]);
        $b = $this->dto(['manufacturer' => $this->manufacturer('m-1', 'Совсем другое имя')]);
        $result = $this->service->computeDiffMarkers([$a, $b]);
        $this->assertFalse($result['manufacturer']);
    }

    public function testTagsCompareAsSortedIdSet(): void
    {
        $a = $this->dto(['tags' => [$this->tag('t-1'), $this->tag('t-2')]]);
        $b = $this->dto(['tags' => [$this->tag('t-2'), $this->tag('t-1')]]);
        $result = $this->service->computeDiffMarkers([$a, $b]);
        $this->assertFalse($result['tags']);
    }

    public function testTagsDifferentSet(): void
    {
        $a = $this->dto(['tags' => [$this->tag('t-1')]]);
        $b = $this->dto(['tags' => [$this->tag('t-2')]]);
        $result = $this->service->computeDiffMarkers([$a, $b]);
        $this->assertTrue($result['tags']);
    }

    public function testDftRangeDiffers(): void
    {
        $a = $this->dto(['dftRange' => ['min' => 80, 'max' => 120, 'tds_dft' => 100, 'type' => 'mic']]);
        $b = $this->dto(['dftRange' => ['min' => 80, 'max' => 130, 'tds_dft' => 100, 'type' => 'mic']]);
        $result = $this->service->computeDiffMarkers([$a, $b]);
        $this->assertTrue($result['dftRange']);
    }

    public function testMaxRecoatNullVsZeroDifferent(): void
    {
        $a = $this->dto(['maxRecoatingInterval' => null]);
        $b = $this->dto(['maxRecoatingInterval' => 0.0]);
        $result = $this->service->computeDiffMarkers([$a, $b]);
        $this->assertTrue($result['maxRecoatingInterval']);
    }

    public function testDryToTouchDiffers(): void
    {
        $a = $this->dto(['dryToTouch' => [['temperature_at' => 20, 'time_in_minutes' => 4.0, 'is_calculated' => false]]]);
        $b = $this->dto(['dryToTouch' => [['temperature_at' => 20, 'time_in_minutes' => 6.0, 'is_calculated' => false]]]);
        $result = $this->service->computeDiffMarkers([$a, $b]);
        $this->assertTrue($result['dryToTouch']);
    }

    /** @param array<string, mixed> $overrides */
    private function dto(array $overrides = []): CoatingDTO
    {
        $dto = new CoatingDTO();
        $dto->id = $overrides['id'] ?? 'c-1';
        $dto->title = $overrides['title'] ?? 'Coating';
        $dto->description = $overrides['description'] ?? 'desc';
        $dto->volumeSolid = $overrides['volumeSolid'] ?? 70;
        $dto->massDensity = $overrides['massDensity'] ?? 1.5;
        $dto->base = $overrides['base'] ?? 'EP';
        $dto->dftRange = $overrides['dftRange'] ?? ['min' => 80, 'max' => 120, 'tds_dft' => 100, 'type' => 'mic'];
        $dto->applicationMinTemp = $overrides['applicationMinTemp'] ?? 5;
        $dto->dryToTouch = $overrides['dryToTouch'] ?? [['temperature_at' => 20, 'time_in_minutes' => 4.0, 'is_calculated' => false]];
        $dto->minRecoatingInterval = $overrides['minRecoatingInterval'] ?? 4.0;
        $dto->maxRecoatingInterval = $overrides['maxRecoatingInterval'] ?? null;
        $dto->fullCure = $overrides['fullCure'] ?? [['temperature_at' => 20, 'time_in_minutes' => 7.0, 'is_calculated' => false]];
        $dto->pack = $overrides['pack'] ?? 20.0;
        $dto->thinner = $overrides['thinner'] ?? null;
        $dto->manufacturer = $overrides['manufacturer'] ?? $this->manufacturer('m-1', 'Производитель');
        $dto->tags = $overrides['tags'] ?? [];
        return $dto;
    }

    private function manufacturer(string $id, string $title): ManufacturerDTO
    {
        $m = new ManufacturerDTO();
        $m->id = $id;
        $m->title = $title;
        return $m;
    }

    private function tag(string $id): CoatingTagDTO
    {
        $t = new CoatingTagDTO();
        $t->id = $id;
        return $t;
    }
}
```

- [ ] **Step 2: Запустить тест — упадёт, нет класса**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith && \
docker-compose exec -T manager_php-fpm vendor/bin/phpunit tests/Unit/Coatings/Application/Service/ComparisonDiffServiceTest.php
```

- [ ] **Step 3: Создать сервис**

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\Service;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTO;
use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use App\Shared\Application\Service\ObjectDiffService;

final class ComparisonDiffService
{
    public function __construct(private readonly ObjectDiffService $diff)
    {
    }

    /**
     * @param list<CoatingDTO> $coatings
     * @return array<string, bool>
     */
    public function computeDiffMarkers(array $coatings): array
    {
        return $this->diff->diff(
            objects: $coatings,
            skip: ['id', 'title', 'description'],
            normalizers: [
                'tags' => function (array $tags): string {
                    $ids = array_map(fn(CoatingTagDTO $t) => $t->id, $tags);
                    sort($ids);
                    return implode(',', $ids);
                },
                'manufacturer' => fn(?ManufacturerDTO $m) => $m?->id ?? '',
                'thinner' => fn(?string $t) => (string)($t ?? ''),
            ],
        );
    }
}
```

- [ ] **Step 4: Запустить тест — должен пройти**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith && \
docker-compose exec -T manager_php-fpm vendor/bin/phpunit tests/Unit/Coatings/Application/Service/ComparisonDiffServiceTest.php
```

Ожидаемо: `OK (13 tests, ...)`

---

## Task 3: ComparisonBasket + BasketFullException

Корзина в Symfony-сессии, лимит 4, idempotent add, runtime guard через исключение.

**Files:**
- Create: `app/src/Coatings/Application/Service/Exception/BasketFullException.php`
- Create: `app/src/Coatings/Application/Service/ComparisonBasket.php`
- Test: `app/tests/Unit/Coatings/Application/Service/ComparisonBasketTest.php`

- [ ] **Step 1: Создать тест**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\Service;

use App\Coatings\Application\Service\ComparisonBasket;
use App\Coatings\Application\Service\Exception\BasketFullException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ComparisonBasketTest extends TestCase
{
    public function testEmptyByDefault(): void
    {
        $basket = $this->makeBasket();
        $this->assertSame([], $basket->ids());
        $this->assertSame(0, $basket->count());
        $this->assertFalse($basket->isFull());
        $this->assertFalse($basket->contains('x'));
    }

    public function testAddStoresId(): void
    {
        $basket = $this->makeBasket();
        $basket->add('a');
        $this->assertSame(['a'], $basket->ids());
        $this->assertTrue($basket->contains('a'));
        $this->assertSame(1, $basket->count());
    }

    public function testAddSameIdIsIdempotent(): void
    {
        $basket = $this->makeBasket();
        $basket->add('a');
        $basket->add('a');
        $this->assertSame(['a'], $basket->ids());
    }

    public function testAddPreservesOrder(): void
    {
        $basket = $this->makeBasket();
        $basket->add('a');
        $basket->add('b');
        $basket->add('c');
        $this->assertSame(['a', 'b', 'c'], $basket->ids());
    }

    public function testAddBeyondLimitThrows(): void
    {
        $basket = $this->makeBasket();
        $basket->add('a');
        $basket->add('b');
        $basket->add('c');
        $basket->add('d');
        $this->expectException(BasketFullException::class);
        $basket->add('e');
    }

    public function testIsFullAtLimit(): void
    {
        $basket = $this->makeBasket();
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $basket->add($id);
        }
        $this->assertTrue($basket->isFull());
    }

    public function testRemoveDropsId(): void
    {
        $basket = $this->makeBasket();
        $basket->add('a');
        $basket->add('b');
        $basket->remove('a');
        $this->assertSame(['b'], $basket->ids());
    }

    public function testRemoveUnknownIsNoop(): void
    {
        $basket = $this->makeBasket();
        $basket->add('a');
        $basket->remove('zzz');
        $this->assertSame(['a'], $basket->ids());
    }

    public function testClearEmpties(): void
    {
        $basket = $this->makeBasket();
        $basket->add('a');
        $basket->add('b');
        $basket->clear();
        $this->assertSame([], $basket->ids());
    }

    public function testStateIsPersistedAcrossInstancesViaSession(): void
    {
        $stack = $this->makeStack();
        (new ComparisonBasket($stack))->add('a');
        $this->assertSame(['a'], (new ComparisonBasket($stack))->ids());
    }

    private function makeStack(): RequestStack
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);
        return $stack;
    }

    private function makeBasket(): ComparisonBasket
    {
        return new ComparisonBasket($this->makeStack());
    }
}
```

- [ ] **Step 2: Запустить тест — упадёт**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith && \
docker-compose exec -T manager_php-fpm vendor/bin/phpunit tests/Unit/Coatings/Application/Service/ComparisonBasketTest.php
```

- [ ] **Step 3: Создать exception**

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\Service\Exception;

final class BasketFullException extends \RuntimeException
{
}
```

- [ ] **Step 4: Создать сервис**

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\Service;

use App\Coatings\Application\Service\Exception\BasketFullException;
use Symfony\Component\HttpFoundation\RequestStack;

final class ComparisonBasket
{
    public const MAX_ITEMS = 4;
    private const SESSION_KEY = 'coating.comparison.basket';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /** @return list<string> */
    public function ids(): array
    {
        return $this->requestStack->getSession()->get(self::SESSION_KEY, []);
    }

    public function count(): int
    {
        return count($this->ids());
    }

    public function isFull(): bool
    {
        return $this->count() >= self::MAX_ITEMS;
    }

    public function contains(string $id): bool
    {
        return in_array($id, $this->ids(), true);
    }

    public function add(string $id): void
    {
        $ids = $this->ids();
        if (in_array($id, $ids, true)) {
            return;
        }
        if (count($ids) >= self::MAX_ITEMS) {
            throw new BasketFullException(sprintf(
                'Корзина сравнения переполнена (максимум %d).',
                self::MAX_ITEMS,
            ));
        }
        $ids[] = $id;
        $this->save($ids);
    }

    public function remove(string $id): void
    {
        $ids = array_values(array_filter(
            $this->ids(),
            static fn(string $x): bool => $x !== $id,
        ));
        $this->save($ids);
    }

    public function clear(): void
    {
        $this->save([]);
    }

    /** @param list<string> $ids */
    private function save(array $ids): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $ids);
    }
}
```

- [ ] **Step 5: Запустить тест — должен пройти**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith && \
docker-compose exec -T manager_php-fpm vendor/bin/phpunit tests/Unit/Coatings/Application/Service/ComparisonBasketTest.php
```

Ожидаемо: `OK (10 tests, ...)`

---

## Task 4: Мутирующие контроллеры (Add / Remove / Clear)

Три коротких action-класса. CSRF-токен под именем `comparison`. Redirect — на `Referer` или fallback на список.

**Files:**
- Create: `app/src/Coatings/Infrastructure/Controller/Comparison/AddAction.php`
- Create: `app/src/Coatings/Infrastructure/Controller/Comparison/RemoveAction.php`
- Create: `app/src/Coatings/Infrastructure/Controller/Comparison/ClearAction.php`

- [ ] **Step 1: AddAction**

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Comparison;

use App\Coatings\Application\Service\ComparisonBasket;
use App\Coatings\Application\Service\Exception\BasketFullException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coating/comparison/add/{id}',
    name: 'app_cabinet_coating_comparison_add',
    methods: ['POST'],
)]
final class AddAction extends AbstractController
{
    public function __construct(private readonly ComparisonBasket $basket)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('comparison', (string) $request->getPayload()->get('_csrf_token'))) {
            $this->addFlash('comparison_error', 'Неверный CSRF-токен.');
            return $this->redirectBack($request);
        }
        try {
            $this->basket->add($id);
        } catch (BasketFullException $e) {
            $this->addFlash('comparison_full', $e->getMessage());
        }
        return $this->redirectBack($request);
    }

    private function redirectBack(Request $request): Response
    {
        $back = $request->headers->get('referer');
        return $this->redirect($back ?: $this->generateUrl('app_cabinet_coating_coating_list'));
    }
}
```

- [ ] **Step 2: RemoveAction**

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Comparison;

use App\Coatings\Application\Service\ComparisonBasket;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coating/comparison/remove/{id}',
    name: 'app_cabinet_coating_comparison_remove',
    methods: ['POST'],
)]
final class RemoveAction extends AbstractController
{
    public function __construct(private readonly ComparisonBasket $basket)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('comparison', (string) $request->getPayload()->get('_csrf_token'))) {
            $this->addFlash('comparison_error', 'Неверный CSRF-токен.');
            return $this->redirectBack($request);
        }
        $this->basket->remove($id);
        return $this->redirectBack($request);
    }

    private function redirectBack(Request $request): Response
    {
        $back = $request->headers->get('referer');
        return $this->redirect($back ?: $this->generateUrl('app_cabinet_coating_coating_list'));
    }
}
```

- [ ] **Step 3: ClearAction**

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Comparison;

use App\Coatings\Application\Service\ComparisonBasket;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coating/comparison/clear',
    name: 'app_cabinet_coating_comparison_clear',
    methods: ['POST'],
)]
final class ClearAction extends AbstractController
{
    public function __construct(private readonly ComparisonBasket $basket)
    {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('comparison', (string) $request->getPayload()->get('_csrf_token'))) {
            $this->addFlash('comparison_error', 'Неверный CSRF-токен.');
            return $this->redirectBack($request);
        }
        $this->basket->clear();
        return $this->redirectBack($request);
    }

    private function redirectBack(Request $request): Response
    {
        $back = $request->headers->get('referer');
        return $this->redirect($back ?: $this->generateUrl('app_cabinet_coating_coating_list'));
    }
}
```

- [ ] **Step 4: Проверить, что роуты зарегистрированы**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith && \
docker-compose exec -T manager_php-fpm php bin/console debug:router | grep comparison
```

Ожидаемо: три роута `_add`, `_remove`, `_clear`.

---

## Task 5: ShowAction + compare.html.twig

GET-страница сравнения. Грузит DTO по id из корзины, считает diff, рендерит таблицу по образцу превью-модалки.

**Files:**
- Create: `app/src/Coatings/Infrastructure/Controller/Comparison/ShowAction.php`
- Create: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/compare.html.twig`

- [ ] **Step 1: ShowAction**

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Comparison;

use App\Coatings\Application\Service\ComparisonBasket;
use App\Coatings\Application\Service\ComparisonDiffService;
use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coating/comparison',
    name: 'app_cabinet_coating_comparison_show',
    methods: ['GET'],
)]
final class ShowAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly ComparisonBasket $basket,
        private readonly ComparisonDiffService $diffService,
    ) {
    }

    public function __invoke(): Response
    {
        $ids = $this->basket->ids();
        if ($ids === []) {
            $this->addFlash('comparison_empty', 'Корзина сравнения пуста.');
            return $this->redirectToRoute('app_cabinet_coating_coating_list');
        }

        $coatings = [];
        foreach ($ids as $id) {
            $result = $this->queryBus->execute(new GetCoatingQuery($id));
            if ($result->coatingDTO !== null) {
                $coatings[] = $result->coatingDTO;
            }
        }

        if ($coatings === []) {
            $this->basket->clear();
            $this->addFlash('comparison_empty', 'Покрытия из корзины не найдены.');
            return $this->redirectToRoute('app_cabinet_coating_coating_list');
        }

        $diff = $this->diffService->computeDiffMarkers($coatings);

        return $this->render('admin/coating/coating/compare.html.twig', [
            'coatings' => $coatings,
            'diff' => $diff,
        ]);
    }
}
```

- [ ] **Step 2: compare.html.twig**

```twig
{% extends '/cabinet/index.html.twig' %}

{% block title %}{{ parent() }} | Сравнение покрытий{% endblock %}

{% block content %}
    {% set cols = coatings|length %}

    <div class="col-lg-12 mx-auto p-4 py-md-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">Сравнение покрытий</h1>
            <div class="d-flex gap-2">
                <a href="{{ path('app_cabinet_coating_coating_list') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> К списку
                </a>
                <form method="post" action="{{ path('app_cabinet_coating_comparison_clear') }}" class="d-inline">
                    <input type="hidden" name="_csrf_token" value="{{ csrf_token('comparison') }}">
                    <button type="submit" class="btn btn-outline-danger btn-sm">Очистить корзину</button>
                </form>
            </div>
        </div>

        {% if cols == 1 %}
            <div class="alert alert-info">Добавьте ещё одно покрытие, чтобы увидеть сравнение.</div>
        {% endif %}

        {# Шапка с названиями покрытий и кнопкой X #}
        <table class="table table-sm align-middle mb-4">
            <thead>
                <tr>
                    <th style="width: 25%;"></th>
                    {% for c in coatings %}
                        <th>
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-semibold">{{ c.title }}</div>
                                    <small class="text-muted">{{ c.manufacturer.title }}</small>
                                </div>
                                <form method="post" action="{{ path('app_cabinet_coating_comparison_remove', {id: c.id}) }}" class="d-inline">
                                    <input type="hidden" name="_csrf_token" value="{{ csrf_token('comparison') }}">
                                    <button type="submit" class="btn-close" aria-label="Убрать"></button>
                                </form>
                            </div>
                        </th>
                    {% endfor %}
                </tr>
            </thead>
        </table>

        {# 1. Основные характеристики #}
        <h6 class="mb-2 mt-1">Основные характеристики</h6>
        <table class="table table-sm mb-4">
            <tbody>
                <tr class="{% if diff.base %}table-warning{% endif %}">
                    <th class="text-muted fw-normal" style="width: 25%;">Тип ЛКМ (основание)</th>
                    {% for c in coatings %}
                        {% set b = c.baseEnum %}
                        <td>
                            {% if b %}
                                {{ b.title }}
                                <span class="text-muted">({{ b.iso }}{% if b.gost|length > 0 %} / {{ b.gost|join(', ') }}{% endif %})</span>
                            {% else %}
                                <span class="text-muted">—</span>
                            {% endif %}
                        </td>
                    {% endfor %}
                </tr>
                <tr class="{% if diff.manufacturer %}table-warning{% endif %}">
                    <th class="text-muted fw-normal">Производитель</th>
                    {% for c in coatings %}<td>{{ c.manufacturer.title }}</td>{% endfor %}
                </tr>
                <tr class="{% if diff.volumeSolid %}table-warning{% endif %}">
                    <th class="text-muted fw-normal">Сухой остаток</th>
                    {% for c in coatings %}<td>{{ c.volumeSolid }} об. %</td>{% endfor %}
                </tr>
                <tr class="{% if diff.massDensity %}table-warning{% endif %}">
                    <th class="text-muted fw-normal">Плотность</th>
                    {% for c in coatings %}<td>{{ c.massDensity }} кг/л</td>{% endfor %}
                </tr>
                <tr class="{% if diff.dftRange %}table-warning{% endif %}">
                    <th class="text-muted fw-normal">Толщина сухой плёнки</th>
                    {% for c in coatings %}
                        <td>
                            {{ c.dftRange.min }}–{{ c.dftRange.max }} мкм
                            <span class="text-muted">(целевая {{ c.dftRange.tds_dft }})</span>
                        </td>
                    {% endfor %}
                </tr>
                <tr class="{% if diff.applicationMinTemp %}table-warning{% endif %}">
                    <th class="text-muted fw-normal">Мин. температура нанесения</th>
                    {% for c in coatings %}<td>{{ c.applicationMinTemp }} °C</td>{% endfor %}
                </tr>
                <tr class="{% if diff.pack %}table-warning{% endif %}">
                    <th class="text-muted fw-normal">Упаковка</th>
                    {% for c in coatings %}<td>{{ c.pack }} л</td>{% endfor %}
                </tr>
                <tr class="{% if diff.thinner %}table-warning{% endif %}">
                    <th class="text-muted fw-normal">Разбавитель</th>
                    {% for c in coatings %}<td>{{ c.thinner ?: '—' }}</td>{% endfor %}
                </tr>
                <tr class="{% if diff.tags %}table-warning{% endif %}">
                    <th class="text-muted fw-normal">Теги</th>
                    {% for c in coatings %}
                        <td>
                            {% for t in c.tags %}
                                <span class="badge text-bg-light border me-1">{{ t.title|default(t.id) }}</span>
                            {% else %}<span class="text-muted">—</span>{% endfor %}
                        </td>
                    {% endfor %}
                </tr>
            </tbody>
        </table>

        {# 2. Сухой на отлип #}
        <h6 class="mb-2 mt-1">Сухой на отлип</h6>
        <table class="table table-sm mb-4">
            <tbody>
                <tr class="{% if diff.dryToTouch %}table-warning{% endif %}">
                    <th class="text-muted fw-normal" style="width: 25%;">При +20 °C</th>
                    {% for c in coatings %}
                        <td>{{ c.dryToTouch[0].time_in_minutes }} мин</td>
                    {% endfor %}
                </tr>
            </tbody>
        </table>

        {# 3. Полное отверждение #}
        <h6 class="mb-2 mt-1">Полное отверждение</h6>
        <table class="table table-sm mb-4">
            <tbody>
                <tr class="{% if diff.fullCure %}table-warning{% endif %}">
                    <th class="text-muted fw-normal" style="width: 25%;">При +20 °C</th>
                    {% for c in coatings %}
                        <td>{{ c.fullCure[0].time_in_minutes }} мин</td>
                    {% endfor %}
                </tr>
            </tbody>
        </table>

        {# 4. Интервал перекрытия #}
        <h6 class="mb-2 mt-1">Интервал перекрытия</h6>
        <table class="table table-sm mb-0">
            <tbody>
                <tr class="{% if diff.minRecoatingInterval %}table-warning{% endif %}">
                    <th class="text-muted fw-normal" style="width: 25%;">Минимальный</th>
                    {% for c in coatings %}<td>{{ c.minRecoatingInterval }} ч</td>{% endfor %}
                </tr>
                <tr class="{% if diff.maxRecoatingInterval %}table-warning{% endif %}">
                    <th class="text-muted fw-normal">Максимальный</th>
                    {% for c in coatings %}
                        <td>
                            {% if c.maxRecoatingInterval %}{{ c.maxRecoatingInterval }} ч{% else %}<span class="text-muted">без верхней границы</span>{% endif %}
                        </td>
                    {% endfor %}
                </tr>
            </tbody>
        </table>
    </div>
{% endblock %}
```

- [ ] **Step 3: Сбросить Twig-кэш**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith && \
docker-compose exec -T manager_php-fpm rm -rf var/cache/dev
```

- [ ] **Step 4: Smoke-проверка через CLI**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith && \
docker-compose exec -T manager_php-fpm php bin/console debug:router app_cabinet_coating_comparison_show
```

Ожидаемо: `GET /cabinet/coating/comparison`.

---

## Task 6: Twig extension с функциями для бара

Двумя функциями: `comparison_basket_ids()`, `comparison_basket_items()`, `comparison_basket_contains($id)`. Используются в `comparison_bar.html.twig` и в кнопке toggle в списке покрытий.

**Files:**
- Create: `app/src/Shared/Infrastructure/Twig/Extension/ComparisonExtension.php`

- [ ] **Step 1: Создать класс**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Twig\Extension;

use App\Coatings\Application\Service\ComparisonBasket;
use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ComparisonExtension extends AbstractExtension
{
    /** @var list<array{id: string, title: string}>|null */
    private ?array $itemsCache = null;

    public function __construct(
        private readonly ComparisonBasket $basket,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('comparison_basket_ids', [$this, 'ids']),
            new TwigFunction('comparison_basket_items', [$this, 'items']),
            new TwigFunction('comparison_basket_contains', [$this, 'contains']),
        ];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return $this->basket->ids();
    }

    public function contains(string $id): bool
    {
        return $this->basket->contains($id);
    }

    /** @return list<array{id: string, title: string}> */
    public function items(): array
    {
        if ($this->itemsCache !== null) {
            return $this->itemsCache;
        }
        $items = [];
        foreach ($this->basket->ids() as $id) {
            $result = $this->queryBus->execute(new GetCoatingQuery($id));
            if ($result->coatingDTO !== null) {
                $items[] = ['id' => $result->coatingDTO->id, 'title' => $result->coatingDTO->title];
            }
        }
        return $this->itemsCache = $items;
    }
}
```

Symfony autowire зарегистрирует extension автоматически (`autoconfigure: true` в `services.yaml`).

- [ ] **Step 2: Сбросить кэш и проверить, что Twig подхватил функции**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith && \
docker-compose exec -T manager_php-fpm rm -rf var/cache/dev && \
docker-compose exec -T manager_php-fpm php bin/console debug:twig --filter=comparison
```

Ожидаемо: список функций `comparison_basket_ids`, `comparison_basket_items`, `comparison_basket_contains`.

---

## Task 7: comparison_bar.html.twig + include в layout

Sticky-бар внизу. Появляется только если в корзине что-то есть. Включается один раз в layout-шаблон кабинета.

**Files:**
- Create: `app/src/Shared/Infrastructure/Templates/components/comparison_bar.html.twig`
- Modify: `app/src/Shared/Infrastructure/Templates/cabinet/index.html.twig:102`

- [ ] **Step 1: Создать comparison_bar.html.twig**

```twig
{% set basketIds = comparison_basket_ids() %}
{% if basketIds|length > 0 %}
    <nav class="comparison-bar fixed-bottom bg-body-tertiary border-top shadow-sm py-2"
         style="padding-left: 1rem; padding-right: 1rem;">
        <div class="container-lg d-flex align-items-center gap-3 flex-wrap">
            <span class="fw-semibold">Сравнение:</span>
            <div class="d-flex flex-wrap gap-2 flex-grow-1">
                {% for item in comparison_basket_items() %}
                    <span class="badge text-bg-light border d-inline-flex align-items-center gap-2 px-2 py-1">
                        <span>{{ item.title }}</span>
                        <form method="post"
                              action="{{ path('app_cabinet_coating_comparison_remove', {id: item.id}) }}"
                              class="d-inline">
                            <input type="hidden" name="_csrf_token" value="{{ csrf_token('comparison') }}">
                            <button type="submit"
                                    class="btn btn-link btn-sm p-0 lh-1 text-decoration-none text-muted"
                                    aria-label="Убрать">&times;</button>
                        </form>
                    </span>
                {% endfor %}
            </div>
            <a href="{{ path('app_cabinet_coating_comparison_show') }}"
               class="btn btn-primary btn-sm {% if basketIds|length < 2 %}disabled{% endif %}"
               {% if basketIds|length < 2 %}aria-disabled="true" tabindex="-1"{% endif %}>
                Сравнить ({{ basketIds|length }})
            </a>
            <form method="post" action="{{ path('app_cabinet_coating_comparison_clear') }}" class="d-inline">
                <input type="hidden" name="_csrf_token" value="{{ csrf_token('comparison') }}">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Очистить</button>
            </form>
        </div>
    </nav>
{% endif %}
```

- [ ] **Step 2: Найти место для include в cabinet/index.html.twig**

В `app/src/Shared/Infrastructure/Templates/cabinet/index.html.twig` строки 102–106:

```twig
                {% endblock %}     {# <- закрытие {% block content %} #}
            </main>
        </div>
    </div>
{% endblock %}            {# <- закрытие {% block body %} #}
```

- [ ] **Step 3: Вставить include после закрытия `block content` (после `</main>` обёртки, перед закрытием block body)**

Изменить блок строк 102–106 на:

```twig
                {% endblock %}
            </main>
        </div>
    </div>

    {% include 'components/comparison_bar.html.twig' %}
{% endblock %}
```

- [ ] **Step 4: Сбросить Twig-кэш**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith && \
docker-compose exec -T manager_php-fpm rm -rf var/cache/dev
```

---

## Task 8: Toggle-кнопка в списке покрытий + smoke-test

Добавить в карточку покрытия (в `coating/index.html.twig`) маленькую форму-кнопку «Добавить к сравнению» / «Убрать из сравнения». Кнопка — sibling с edit/delete (но видна **всем** авторизованным, не только админам). После — ручная проверка пайплайна в браузере.

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig:89-103`

- [ ] **Step 1: Найти существующий блок actions в карточке**

В `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig` сейчас:

```twig
                            {% if canEdit %}
                                <div class="d-flex gap-2 flex-shrink-0">
                                    {% include '/components/edit_delete.html.twig'
                                        with {
                                        'edit': 'app_cabinet_coating_coating_update',
                                        'delete':'app_cabinet_coating_coating_delete',
                                        'duplicate': 'app_cabinet_coating_coating_create',
                                        'id': coating.id,
                                        'title': coating.title,
                                        'name': "покрытие"
                                    } %}
                                </div>
                            {% endif %}
```

- [ ] **Step 2: Вставить toggle-форму над `{% if canEdit %}`**

Получаем (внешний `<div class="d-flex gap-2 flex-shrink-0">` теперь оборачивает обе формы, чтобы toggle и edit/delete жили рядом):

```twig
                            <div class="d-flex gap-2 flex-shrink-0 align-items-center">
                                {% set inBasket = comparison_basket_contains(coating.id) %}
                                <form method="post"
                                      action="{{ path(inBasket
                                          ? 'app_cabinet_coating_comparison_remove'
                                          : 'app_cabinet_coating_comparison_add',
                                          {id: coating.id}) }}"
                                      class="d-inline">
                                    <input type="hidden" name="_csrf_token" value="{{ csrf_token('comparison') }}">
                                    <button type="submit"
                                            class="btn btn-sm {{ inBasket ? 'btn-primary' : 'btn-outline-primary' }}"
                                            title="{{ inBasket ? 'Убрать из сравнения' : 'Добавить к сравнению' }}">
                                        <i class="bi bi-bar-chart"></i>
                                    </button>
                                </form>
                                {% if canEdit %}
                                    {% include '/components/edit_delete.html.twig'
                                        with {
                                        'edit': 'app_cabinet_coating_coating_update',
                                        'delete':'app_cabinet_coating_coating_delete',
                                        'duplicate': 'app_cabinet_coating_coating_create',
                                        'id': coating.id,
                                        'title': coating.title,
                                        'name': "покрытие"
                                    } %}
                                {% endif %}
                            </div>
```

(Старый блок `{% if canEdit %}<div class="d-flex gap-2 flex-shrink-0">...</div>{% endif %}` удаляем целиком, заменяя на разметку выше.)

- [ ] **Step 3: Сбросить кэш**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith && \
docker-compose exec -T manager_php-fpm rm -rf var/cache/dev
```

- [ ] **Step 4: Прогнать весь юнит-тестпак**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith && \
docker-compose exec -T manager_php-fpm vendor/bin/phpunit
```

Ожидаемо: количество новых тестов прибавилось (≈ +32 теста: 9 ObjectDiffService, 13 ComparisonDiff, 10 ComparisonBasket). Падают только два пре-существующих в `Users/Functional` про `token`.

- [ ] **Step 5: Ручная smoke-проверка в браузере**

Прохожу сценарий:

1. Открываю `/cabinet/coating/coating/list` — у каждой карточки иконка-кнопка «весы» (outline).
2. Жму её на двух разных покрытиях — внизу появляется sticky-бар с двумя бейджами и кнопкой «Сравнить (2)».
3. Перехожу на другую страницу кабинета (например, список производителей) — бар продолжает быть видимым.
4. Возвращаюсь на список покрытий — у выбранных покрытий иконка filled (`btn-primary`), tooltip «Убрать из сравнения».
5. Жму «Сравнить (2)» в баре — попадаю на `/cabinet/coating/comparison`. Вижу шапку, таблицы со всеми секциями превью, строки с различиями подсвечены `table-warning`.
6. Жму крестик на колонке одного покрытия — оно убирается, остаётся одна колонка с алертом «Добавьте ещё одно покрытие...».
7. Жму «Очистить корзину» — редирект на список, бар исчезает.
8. Жму ту же иконку второй раз на одном покрытии — она снимается, бар обновляется (или исчезает, если корзина пуста).
9. Добавляю 5-е покрытие — флеш «Корзина сравнения переполнена...».

---

## Self-review (свериться со спекой)

После всех тасков пробегусь по `docs/superpowers/specs/2026-06-08-coating-comparison-design.md`:

- ✅ ComparisonBasket с лимитом 4, idempotent add, BasketFullException — Task 3
- ✅ ComparisonDiffService с правилами для tags/manufacturer/thinner — Task 2 (через ObjectDiffService — Task 1)
- ✅ Routes Show/Add/Remove/Clear с CSRF — Tasks 4–5
- ✅ compare.html.twig структура совпадает с превью-модалкой — Task 5
- ✅ Подсветка `table-warning` на `<tr>` — Task 5
- ✅ Sticky-бар в layout — Task 7
- ✅ Toggle-кнопка на карточке, доступна всем (без `canEdit`) — Task 8
- ✅ Empty-basket / not-found case в ShowAction — Task 5 (redirect с флешем)
- ✅ Unit-тесты на ComparisonBasket, ObjectDiffService, ComparisonDiffService — Tasks 1–3
- ✅ Description не входит в diff — Task 2 (skip-list)
- ✅ Twig-функции для бара — Task 6
