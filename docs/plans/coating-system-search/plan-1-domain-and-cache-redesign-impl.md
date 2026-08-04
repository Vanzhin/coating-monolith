# CoatingSystem: Domain + Event-Driven Cache Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Перепроектировать read-модель поиска систем покрытий: домен `CoatingSystem` очистить от кэш-полей и сервисов; кэш для поиска перенести в две отдельные таблицы, обновляемые синхронно через domain events и event.bus subscriber-ы.

**Architecture:** Агрегат публикует событие `CoatingSystemMutated`/`CoatingMutated`. `PublishDomainEventsOnFlushListener` перехватывает на onFlush, отправляет в event.bus (sync). Subscriber-ы вызывают публичные runtime-методы агрегата и записывают результат в кэш-таблицы `coating_system_search` (1:1) и `coating_system_compliance` (1:N).

**Tech Stack:** PHP 8.3, Symfony 7 + Messenger, Doctrine ORM (XML mapping), PostgreSQL 16 (tsvector/GIN/JSONB), PHPUnit 9.

## Global Constraints

- Правило `CLAUDE.md`: производные величины считаются runtime-методами домена; кэш для поиска живёт в infrastructure и обновляется через события, а не полями агрегата.
- **Коммиты только по явной команде пользователя**: шаги "commit" в задачах — плейсхолдеры, не выполнять без апрува. Правки живут в рабочем дереве до команды.
- Все миграции идемпотентные (`IF NOT EXISTS`/`IF EXISTS`).
- Сообщения `AppException` — на русском, для пользователя.
- ORM — только XML mapping в `app/src/Coatings/Infrastructure/Database/ORM/`.
- Тесты: Unit в `tests/Unit/Coatings/`, Functional (с реальной БД) в `tests/Functional/Coatings/`.
- Спецификация: `docs/plans/coating-system-search/plan-1-domain-and-cache-redesign.md`.

---

## File Structure

**Создать**:

| Файл | Ответственность |
|---|---|
| `app/src/Coatings/Domain/Event/CoatingSystemMutated.php` | Событие: система изменилась (id системы) |
| `app/src/Coatings/Domain/Event/CoatingMutated.php` | Событие: покрытие изменилось (id покрытия) |
| `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceMatch.php` | VO — одно совпадение (стандарт+категория+долговечность) |
| `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceMatches.php` | Коллекция VO |
| `app/src/Coatings/Application/Event/RefreshCacheOnCoatingSystemMutatedHandler.php` | Sync-subscriber: обновляет кэш одной системы |
| `app/src/Coatings/Application/Event/RefreshCacheOnCoatingMutatedHandler.php` | Sync-subscriber: обновляет кэш всех систем с покрытием |
| `app/src/Coatings/Infrastructure/Cache/CoatingSystemSearchCacheRepository.php` | UPSERT в `coating_system_search` |
| `app/src/Coatings/Infrastructure/Cache/CoatingSystemComplianceCacheRepository.php` | DELETE+INSERT в `coating_system_compliance` |
| `app/src/Shared/Infrastructure/Database/Migrations/Version20260801190000.php` | Финальный вид схемы кэша |
| `app/src/Coatings/Infrastructure/Console/RebuildCoatingSystemSearchCacheCommand.php` | Backfill: перепроектировать все системы |

**Изменить**:

| Файл | Что |
|---|---|
| `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php` | Удалить chainValidator/поля-кэши/recalculateDerivedFields; публичные runtime-методы; приватный `assertLayersAreChainable`; `raise(new CoatingSystemMutated)` в постMutate и в мутирующих сеттерах |
| `app/src/Coatings/Domain/Aggregate/Coating/Coating.php` | `raise(new CoatingMutated)` в setBase/setIsZincRich/setApplicationMinTemp/setDftRange/setMinRecoatingInterval/setTitle/setDescription |
| `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceEvaluator.php` | `evaluate(): ComplianceMatches` вместо голого array |
| `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/CoatingSystem.CoatingSystem.orm.xml` | Убрать mapping для minBuildingTimeAt20Minutes/maxLayerApplicationMinTemp/complianceMatches |
| `app/src/Coatings/Domain/Repository/CoatingSystemRepositoryInterface.php` | `findByLayerCoatingId(string $coatingId): array` |
| `app/src/Coatings/Infrastructure/Repository/CoatingSystemRepository.php` | Реализация `findByLayerCoatingId` через QueryBuilder JOIN layers |
| Все 7 mutation-handler-ов CoatingSystem | Убрать `CoatingSystemChainValidatorInterface` из конструктора и `setChainValidator` из `__invoke` |
| `app/config/services.yaml` | Удалить регистрацию проектора и chainValidator |

**Удалить**:
- `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemChainValidator.php`
- `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemChainValidatorInterface.php`
- `app/src/Coatings/Infrastructure/Projector/CoatingSystemSearchProjector.php`
- `app/tests/Functional/Coatings/Infrastructure/Projector/CoatingSystemSearchProjectorTest.php` (переписать под subscriber-ы)

---

## Task 1: Domain Events + Aggregate::raise integration

**Files:**
- Create: `app/src/Coatings/Domain/Event/CoatingSystemMutated.php`
- Create: `app/src/Coatings/Domain/Event/CoatingMutated.php`
- Test: `app/tests/Unit/Coatings/Domain/Event/CoatingSystemMutatedTest.php`
- Test: `app/tests/Unit/Coatings/Domain/Event/CoatingMutatedTest.php`

**Interfaces:**
- Consumes: `App\Shared\Domain\Event\EventInterface` (маркерный, уже есть).
- Produces: два final readonly класса-события с `public readonly string $systemId`/`$coatingId`.

- [ ] **Step 1: Write failing test for CoatingSystemMutated**

```php
<?php declare(strict_types=1);
namespace App\Tests\Unit\Coatings\Domain\Event;

use App\Coatings\Domain\Event\CoatingSystemMutated;
use App\Shared\Domain\Event\EventInterface;
use PHPUnit\Framework\TestCase;

final class CoatingSystemMutatedTest extends TestCase
{
    public function test_stores_system_id_and_implements_marker(): void
    {
        $event = new CoatingSystemMutated('018f-abc');
        self::assertSame('018f-abc', $event->systemId);
        self::assertInstanceOf(EventInterface::class, $event);
    }
}
```

- [ ] **Step 2: Run test — verify FAIL** (`class not found`)

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Event/CoatingSystemMutatedTest.php
```

- [ ] **Step 3: Implement CoatingSystemMutated**

```php
<?php declare(strict_types=1);
namespace App\Coatings\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

final readonly class CoatingSystemMutated implements EventInterface
{
    public function __construct(public string $systemId)
    {
    }
}
```

- [ ] **Step 4: Run test — verify PASS**

- [ ] **Step 5: Same test+implementation cycle for CoatingMutated** (аналогично, `public string $coatingId`)

- [ ] **Step 6: (skip commit — user handles git)**

---

## Task 2: ComplianceMatch + ComplianceMatches VO

**Files:**
- Create: `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceMatch.php`
- Create: `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceMatches.php`
- Test: `app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/ComplianceMatchTest.php`
- Test: `app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/ComplianceMatchesTest.php`

**Interfaces:**
- Consumes: `ComplianceStandard` enum (уже есть).
- Produces:
  - `ComplianceMatch` — final readonly, `public ComplianceStandard $standard, public string $category, public string $durability`, `jsonSerialize(): array`, `static fromArray(array $row): self`.
  - `ComplianceMatches` — final class implements `IteratorAggregate<int, ComplianceMatch>`, `Countable`, `JsonSerializable`. Методы: `add(ComplianceMatch $m): void`, `toArray(): array`, `jsonSerialize(): array`, `count(): int`, `getIterator(): Traversable`.

- [ ] **Step 1: Write failing test для ComplianceMatch (json roundtrip)**

```php
public function test_round_trip_json(): void
{
    $m = new ComplianceMatch(ComplianceStandard::ISO_12944, 'C4', 'HIGH');
    self::assertSame(['standard' => 'ISO_12944', 'category' => 'C4', 'durability' => 'HIGH'], $m->jsonSerialize());
    $restored = ComplianceMatch::fromArray($m->jsonSerialize());
    self::assertEquals($m, $restored);
}
```

- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement ComplianceMatch** (см. интерфейс выше)
- [ ] **Step 4: Run — PASS**

- [ ] **Step 5: Write failing test для ComplianceMatches**

```php
public function test_collects_matches_and_serializes(): void
{
    $matches = new ComplianceMatches();
    $matches->add(new ComplianceMatch(ComplianceStandard::ISO_12944, 'C4', 'HIGH'));
    $matches->add(new ComplianceMatch(ComplianceStandard::ISO_12944, 'C3', 'MEDIUM'));

    self::assertCount(2, $matches);
    self::assertCount(2, iterator_to_array($matches));
    self::assertSame([
        ['standard' => 'ISO_12944', 'category' => 'C4', 'durability' => 'HIGH'],
        ['standard' => 'ISO_12944', 'category' => 'C3', 'durability' => 'MEDIUM'],
    ], $matches->jsonSerialize());
}
```

- [ ] **Step 6: Implement ComplianceMatches**

```php
final class ComplianceMatches implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @var list<ComplianceMatch> */
    private array $items = [];

    public function add(ComplianceMatch $m): void { $this->items[] = $m; }
    public function count(): int { return count($this->items); }
    public function getIterator(): \Traversable { yield from $this->items; }
    /** @return list<array{standard: string, category: string, durability: string}> */
    public function jsonSerialize(): array { return array_map(fn ($m) => $m->jsonSerialize(), $this->items); }
    /** @return list<ComplianceMatch> */
    public function toArray(): array { return $this->items; }
}
```

- [ ] **Step 7: Run — PASS**

---

## Task 3: ComplianceEvaluator returns ComplianceMatches

**Files:**
- Modify: `app/src/Coatings/Domain/Aggregate/CoatingSystem/ComplianceEvaluator.php`
- Test: `app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/ComplianceEvaluatorTest.php` (существующий, обновить)

**Interfaces:**
- Consumes: `ComplianceMatch`, `ComplianceMatches` (Task 2).
- Produces: `ComplianceEvaluator::evaluate(CoatingSystem $system): ComplianceMatches`.

- [ ] **Step 1: Обновить существующий тест `ComplianceEvaluatorTest`**

Изменить ожидания: вместо `array` ожидать `ComplianceMatches`; вместо ассоциативных массивов — сравнивать через `ComplianceMatch` объекты или `toArray()`. Пример:

```php
public function test_matches_iso_c4_high_for_zinc_rich_ep(): void
{
    $result = $this->evaluator->evaluate($this->buildZincRichSystem());
    self::assertInstanceOf(ComplianceMatches::class, $result);
    $matches = $result->toArray();
    self::assertContainsEquals(
        new ComplianceMatch(ComplianceStandard::ISO_12944, 'C4', 'HIGH'),
        $matches,
    );
}
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Изменить `ComplianceEvaluator::evaluate`**

Заменить возврат `array $result` на `ComplianceMatches`. Внутри цикла вместо
```
$result[] = ['standard' => $rule->standard, 'category' => ..., 'durability' => ...];
```
делать
```
$result->add(new ComplianceMatch($rule->standard, $rule->category, $rule->durability));
```
Заменить return type: `public function evaluate(CoatingSystem $system): ComplianceMatches`.

- [ ] **Step 4: Run — PASS**

---

## Task 4: CoatingSystem domain — очистка и runtime-методы

**Files:**
- Modify: `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php`
- Modify: `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/CoatingSystem.CoatingSystem.orm.xml`
- Test: `app/tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemTest.php` (существующий, расширить)

**Interfaces:**
- Consumes: `Aggregate::raise` (уже есть), `CoatingSystemMutated` (Task 1), `ComplianceEvaluator::evaluate → ComplianceMatches` (Task 3).
- Produces:
  - Публичные методы: `minBuildingTimeAt20Minutes(): ?int`, `maxLayerApplicationMinTemp(): ?int`, `complianceMatches(ComplianceEvaluator $evaluator): ComplianceMatches`.
  - Приватный: `assertLayersAreChainable(): void`.
  - Событие `CoatingSystemMutated` при мутациях.

- [ ] **Step 1: Тест публичных runtime-методов**

Расширить `CoatingSystemTest`:
```php
public function test_min_building_time_sums_interpolated_intervals_except_top(): void
{
    $sys = $this->newSystem();
    $coatingA = $this->makeCoating(sourceMinutes: 240, tdsDft: 100);
    $coatingB = $this->makeCoating(applicationMinTemp: 10);
    $sys->appendLayer($coatingA, 80);   // 240*80/100 = 192
    $sys->appendLayer($coatingB, 80);   // top, не участвует
    self::assertSame(192, $sys->minBuildingTimeAt20Minutes());
    self::assertSame(10, $sys->maxLayerApplicationMinTemp());
}

public function test_mutation_raises_CoatingSystemMutated_event(): void
{
    $sys = $this->newSystem();
    $sys->appendLayer($this->newCoating(), 80);
    $events = $sys->pullEvents();
    self::assertCount(1, $events);
    self::assertInstanceOf(CoatingSystemMutated::class, $events[0]);
    self::assertSame($sys->getId(), $events[0]->systemId);
}

public function test_asserts_layers_are_chainable_via_canBecoveredBy(): void
{
    $this->expectException(AppException::class);
    $this->expectExceptionMessageMatches('/несовместим/');
    // ESI поверх FEVE не совместимо (по CoatingBase::allowedPrimers)
    $sys = $this->newSystem();
    $sys->appendLayer($this->makeCoating(CoatingBase::FEVE), 80);
    $sys->appendLayer($this->makeCoating(CoatingBase::ESI), 80);
}
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Правки CoatingSystem.php**

Убрать поля:
```
private ?int $minBuildingTimeAt20Minutes = null;   // УДАЛИТЬ
private ?int $maxLayerApplicationMinTemp = null;    // УДАЛИТЬ
```

Убрать из конструктора (пока оставить `chainValidator` — уберётся в Task 6, после чистки handler-ов):
```
private ?CoatingSystemChainValidatorInterface $chainValidator = null,
```
Оставить пока — важно, что тесты не сломаются в промежутке. Конкретное удаление — Task 6.

Убрать методы: `recalculateDerivedFields`, `computeMinBuildingTimeAt20Minutes`, `computeMaxLayerApplicationMinTemp` (были приватными). Их логика встраивается в новые публичные:

```php
public function minBuildingTimeAt20Minutes(): ?int
{
    $ordered = $this->getLayers();
    if ($ordered->isEmpty()) return null;
    $topLayer = $ordered->last();
    $sum = 0;
    foreach ($ordered as $layer) {
        if ($layer === $topLayer) continue;
        $interval = $layer->getCoating()->interpolatedMinRecoatMinutesAt20($layer->getDft());
        if (null === $interval) return null;
        $sum += $interval;
    }
    return $sum;
}

public function maxLayerApplicationMinTemp(): ?int
{
    if ($this->layers->isEmpty()) return null;
    $max = null;
    foreach ($this->layers as $layer) {
        $temp = $layer->getCoating()->getApplicationMinTemp();
        if (null === $max || $temp > $max) $max = $temp;
    }
    return $max;
}

public function complianceMatches(ComplianceEvaluator $evaluator): ComplianceMatches
{
    return $evaluator->evaluate($this);
}
```

Убрать геттеры `getMinBuildingTimeAt20Minutes`/`getMaxLayerApplicationMinTemp` (теперь есть публичные `minBuildingTimeAt20Minutes()`/`maxLayerApplicationMinTemp()`).

Добавить приватный:
```php
private function assertLayersAreChainable(): void
{
    $layers = $this->getLayers()->toArray();
    $n = count($layers);
    for ($i = 0; $i < $n - 1; ++$i) {
        $current = $layers[$i]->getCoating()->getBase();
        $next = $layers[$i + 1]->getCoating()->getBase();
        if (!$current->canBecoveredBy($next)) {
            throw new AppException(sprintf(
                'Слой %d (%s) несовместим со слоем %d (%s): поверх %s нельзя наносить %s.',
                $i + 1, $current->title(), $i + 2, $next->title(), $current->title(), $next->title(),
            ));
        }
    }
}
```

Изменить `postMutate`:
```php
private function postMutate(): void
{
    $this->assertPositionsAreDense();
    $this->assertLayersAreChainable();
    $this->raise(new CoatingSystemMutated($this->getId()));
    $this->touch();
}
```

Добавить `$this->raise(new CoatingSystemMutated($this->getId()))` в мутирующие сеттеры, не идущие через `postMutate`:
- `setTitle`, `setDescription`, `setSubstrate`, `setSurfaceTreatment`, `setSubstrateAndTreatment`.
- `addTag` (внутри if-added), `removeTag` (внутри if-removed), `replaceTags` (всегда).

- [ ] **Step 4: Убрать ORM mapping для min/max**

`CoatingSystem.CoatingSystem.orm.xml` — удалить строки:
```xml
<field name="minBuildingTimeAt20Minutes" .../>
<field name="maxLayerApplicationMinTemp" .../>
```
(если `complianceMatches` тоже добавлен — тоже удалить.)

- [ ] **Step 5: Run all Coatings tests — verify PASS**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Coatings
```

Если ломается: обновить сломанные фикстуры/тесты, которые ожидали старую сигнатуру `getMinBuildingTimeAt20Minutes()`. Заменить на `minBuildingTimeAt20Minutes()`.

---

## Task 5: Coating raises CoatingMutated in setters

**Files:**
- Modify: `app/src/Coatings/Domain/Aggregate/Coating/Coating.php`
- Test: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTest.php` (расширить)

**Interfaces:**
- Consumes: `CoatingMutated` (Task 1), `Aggregate::raise` (уже есть).
- Produces: событие `CoatingMutated($coatingId)` при мутирующих сеттерах, влияющих на compliance/FTS.

- [ ] **Step 1: Тест события при setBase**

```php
public function test_setBase_raises_CoatingMutated(): void
{
    $c = $this->makeCoating(CoatingBase::EP, 50, 500);
    $c->pullEvents(); // очистить события от конструктора
    $c->setBase(CoatingBase::PUR);
    $events = $c->pullEvents();
    self::assertCount(1, $events);
    self::assertInstanceOf(CoatingMutated::class, $events[0]);
    self::assertSame($c->getId(), $events[0]->coatingId);
}
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Добавить raise в сеттеры**

В `Coating.php` в конце каждого из следующих сеттеров добавить `$this->raise(new CoatingMutated($this->getId()));`:
- `setTitle`
- `setDescription`
- `setBase`
- `setIsZincRich`
- `setApplicationMinTemp`
- `setDftRange`
- `setMinRecoatingInterval`

Важно: если конструктор вызывает эти сеттеры — при `new Coating()` события будут накоплены. `pullEvents` вызывается в тестах после конструктора для чистки.

- [ ] **Step 4: Run — PASS**

- [ ] **Step 5: Аналогично тесты для остальных 6 сеттеров** — по одному тесту на сеттер.

---

## Task 6: Delete CoatingSystemChainValidator + clean handlers

**Files:**
- Delete: `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemChainValidator.php`
- Delete: `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemChainValidatorInterface.php`
- Modify: `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php` — убрать `?CoatingSystemChainValidatorInterface $chainValidator = null` из конструктора и `setChainValidator`; из `postMutate` уже убран (Task 4).
- Modify: 7 handler-ов (см. ниже).
- Modify: `app/config/services.yaml` — снять регистрацию `ChainValidator`, если явно перечислен.

**Handler-ы для правки**:
- `CreateCoatingSystemCommandHandler.php`
- `UpdateCoatingSystemMetadataCommandHandler.php`
- `AppendLayerCommandHandler.php`
- `InsertLayerAtCommandHandler.php`
- `RemoveLayerAtCommandHandler.php`
- `MoveLayerCommandHandler.php`
- `UpdateLayerDftCommandHandler.php`

**Interfaces:**
- Consumes: Ничего нового.
- Produces: Все mutation-handler-ы становятся тонкими без chainValidator.

- [ ] **Step 1: Проверить каскад удаления**

```bash
grep -rn "CoatingSystemChainValidator" app/src app/tests app/config
```

- [ ] **Step 2: Удалить два файла**

```bash
rm app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemChainValidator.php
rm app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystemChainValidatorInterface.php
```

- [ ] **Step 3: В CoatingSystem.php**

Убрать из конструктора:
```
private ?CoatingSystemChainValidatorInterface $chainValidator = null,
```
Убрать метод `setChainValidator`.
Убрать импорт `use ...CoatingSystemChainValidatorInterface;`.

- [ ] **Step 4: В каждом из 7 handler-ов**

Из конструктора убрать `private CoatingSystemChainValidatorInterface $chainValidator,`.
Из `__invoke` убрать `$system->setChainValidator($this->chainValidator);`.
Из `use` убрать импорт интерфейса.
При `new CoatingSystem(...)` (только в CreateCoatingSystemCommandHandler) убрать последний аргумент `$this->chainValidator`.

- [ ] **Step 5: `services.yaml` — убрать явные регистрации**, если есть.

- [ ] **Step 6: Прогон unit + functional tests**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Coatings tests/Functional/Coatings
```

Ожидаемо часть functional-тестов упадёт на кэш/проекторе (следующие таски). На данном шаге принимаем.

- [ ] **Step 7: phpstan clean**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress
```

Ожидаемо 0 ошибок.

---

## Task 7: Migration Version20260801190000 — финальный вид схемы

**Files:**
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20260801190000.php`

**Interfaces:**
- Consumes: Ничего кроме текущего состояния БД.
- Produces: Схема после миграции — `coating_system` без min/max/compliance-полей; `coating_system_search` с (system_id, min_building_time_at_20_minutes, max_layer_application_min_temp, search_tsvector) + индексы.

- [ ] **Step 1: Создать миграцию**

```php
<?php declare(strict_types=1);
namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Финальный вид схемы кэша поиска систем: min/max/tsvector — в отдельной таблице
 * coating_system_search (1:1). Compliance — в существующей coating_system_compliance (1:N).
 * Никаких кэш-полей на coating_system.
 *
 * Идемпотентно: работает независимо от того, какие из промежуточных миграций
 * (170000, 180000) уже накатаны в среде.
 */
final class Version20260801190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'coating_system: drop cache columns; coating_system_search: restore min/max/tsvector layout.';
    }

    public function up(Schema $schema): void
    {
        // Убрать лишнее с coating_system
        $this->addSql('DROP INDEX IF EXISTS idx_cs_min_building');
        $this->addSql('DROP INDEX IF EXISTS idx_cs_max_app_temp');
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system
              DROP COLUMN IF EXISTS min_building_time_at_20_minutes,
              DROP COLUMN IF EXISTS max_layer_application_min_temp
        SQL);
        $this->addSql('DROP INDEX IF EXISTS idx_cs_compliance_matches');
        $this->addSql('ALTER TABLE coating_system DROP COLUMN IF EXISTS compliance_matches');

        // Восстановить min/max в coating_system_search
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system_search
              ADD COLUMN IF NOT EXISTS min_building_time_at_20_minutes INT,
              ADD COLUMN IF NOT EXISTS max_layer_application_min_temp  INT
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_min_building ON coating_system_search (min_building_time_at_20_minutes)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_max_app_temp ON coating_system_search (max_layer_application_min_temp)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_css_max_app_temp');
        $this->addSql('DROP INDEX IF EXISTS idx_css_min_building');
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system_search
              DROP COLUMN IF EXISTS max_layer_application_min_temp,
              DROP COLUMN IF EXISTS min_building_time_at_20_minutes
        SQL);
    }
}
```

- [ ] **Step 2: Прогнать миграцию на test-БД**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli bin/console doctrine:migrations:migrate --env=test -n
```

Ожидаемо: OK, 1 migration executed.

- [ ] **Step 3: Проверить структуру таблиц**

```bash
docker compose -f docker-compose.test.yml exec test_db psql -U app_test app_test -c "\d coating_system_search"
docker compose -f docker-compose.test.yml exec test_db psql -U app_test app_test -c "\d coating_system"
```

Убедиться: `coating_system_search` содержит system_id + min + max + tsvector; `coating_system` не содержит cache-колонок.

---

## Task 8: CoatingSystemSearchCacheRepository

**Files:**
- Create: `app/src/Coatings/Infrastructure/Cache/CoatingSystemSearchCacheRepository.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Cache/CoatingSystemSearchCacheRepositoryTest.php`

**Interfaces:**
- Consumes: `Doctrine\DBAL\Connection`, `CoatingSystem` (Task 4).
- Produces: два публичных метода — `upsert(CoatingSystem $system): void`, `delete(string $systemId): void`.

- [ ] **Step 1: Write failing test — upsert INSERT**

```php
public function test_upsert_inserts_new_row(): void
{
    $system = $this->buildSystemWithLayerAndPersist();
    $this->repo->upsert($system);
    $row = $this->fetchRow($system->getId());
    self::assertSame(0, (int) $row['min_building_time_at_20_minutes']);  // 1 layer → 0
    self::assertSame(5, (int) $row['max_layer_application_min_temp']);
    self::assertNotEmpty($row['search_tsvector']);
}
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Реализация**

```php
<?php declare(strict_types=1);
namespace App\Coatings\Infrastructure\Cache;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use Doctrine\DBAL\Connection;

final class CoatingSystemSearchCacheRepository
{
    public function __construct(private readonly Connection $conn)
    {
    }

    public function upsert(CoatingSystem $system): void
    {
        $this->conn->executeStatement(
            <<<'SQL'
                INSERT INTO coating_system_search
                    (system_id, min_building_time_at_20_minutes, max_layer_application_min_temp, search_tsvector)
                VALUES
                    (:id, :sum, :max_temp, to_tsvector('russian', :doc))
                ON CONFLICT (system_id) DO UPDATE
                SET min_building_time_at_20_minutes = EXCLUDED.min_building_time_at_20_minutes,
                    max_layer_application_min_temp  = EXCLUDED.max_layer_application_min_temp,
                    search_tsvector                 = EXCLUDED.search_tsvector
            SQL,
            [
                'id' => $system->getId(),
                'sum' => $system->minBuildingTimeAt20Minutes(),
                'max_temp' => $system->maxLayerApplicationMinTemp(),
                'doc' => $this->buildFullTextSearchDocument($system),
            ],
        );
    }

    public function delete(string $systemId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM coating_system_search WHERE system_id = :id',
            ['id' => $systemId],
        );
    }

    private function buildFullTextSearchDocument(CoatingSystem $system): string
    {
        $parts = [$system->getTitle(), $system->getDescription()];
        foreach ($system->getLayers() as $layer) {
            $parts[] = $layer->getCoating()->getManufacturer()->getTitle();
            $parts[] = $layer->getCoating()->getTitle();
        }
        foreach ($system->getTags() as $tag) {
            $parts[] = $tag->getTitle();
        }
        return implode(' ', array_filter($parts, static fn (string $p) => '' !== trim($p)));
    }
}
```

- [ ] **Step 4: Run — PASS**

- [ ] **Step 5: Test upsert повторно (ON CONFLICT DO UPDATE)**

```php
public function test_upsert_updates_existing_row(): void
{
    $system = $this->buildSystemWithLayerAndPersist();
    $this->repo->upsert($system);
    $system->setTitle('Изменено');
    $this->repo->upsert($system);

    $count = (int) $this->conn->fetchOne(
        'SELECT COUNT(*) FROM coating_system_search WHERE system_id = ?',
        [$system->getId()],
    );
    self::assertSame(1, $count);
}
```

- [ ] **Step 6: Test delete + FK cascade**

```php
public function test_row_cascade_deleted_with_system(): void
{
    $system = $this->buildSystemWithLayerAndPersist();
    $this->repo->upsert($system);
    // удалить систему через ORM → FK ON DELETE CASCADE
    $this->em->remove($system);
    $this->em->flush();
    $count = (int) $this->conn->fetchOne(
        'SELECT COUNT(*) FROM coating_system_search WHERE system_id = ?',
        [$system->getId()],
    );
    self::assertSame(0, $count);
}
```

- [ ] **Step 7: Run — PASS**

---

## Task 9: CoatingSystemComplianceCacheRepository

**Files:**
- Create: `app/src/Coatings/Infrastructure/Cache/CoatingSystemComplianceCacheRepository.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Cache/CoatingSystemComplianceCacheRepositoryTest.php`

**Interfaces:**
- Consumes: `Connection`, `CoatingSystem`, `ComplianceEvaluator` (Task 3, возвращает `ComplianceMatches`).
- Produces: методы `rewrite(CoatingSystem $system, ComplianceEvaluator $evaluator): void`, `delete(string $systemId): void`.

- [ ] **Step 1: Failing test**

```php
public function test_rewrite_replaces_all_rows_for_system(): void
{
    $system = $this->buildZincRichEpSystemAndPersist();
    $this->repo->rewrite($system, $this->evaluator);
    $rows = $this->conn->fetchAllAssociative(
        'SELECT standard, category, durability FROM coating_system_compliance WHERE system_id = ? ORDER BY category, durability',
        [$system->getId()],
    );
    self::assertGreaterThan(0, count($rows));
    self::assertContains(['standard' => 'ISO_12944', 'category' => 'C4', 'durability' => 'HIGH'], $rows);
}
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Реализация**

```php
<?php declare(strict_types=1);
namespace App\Coatings\Infrastructure\Cache;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use Doctrine\DBAL\Connection;

final class CoatingSystemComplianceCacheRepository
{
    public function __construct(private readonly Connection $conn)
    {
    }

    public function rewrite(CoatingSystem $system, ComplianceEvaluator $evaluator): void
    {
        $this->conn->executeStatement(
            'DELETE FROM coating_system_compliance WHERE system_id = :id',
            ['id' => $system->getId()],
        );
        foreach ($system->complianceMatches($evaluator) as $match) {
            $this->conn->executeStatement(
                'INSERT INTO coating_system_compliance (system_id, standard, category, durability)
                 VALUES (:id, :std, :cat, :dur)',
                [
                    'id' => $system->getId(),
                    'std' => $match->standard->value,
                    'cat' => $match->category,
                    'dur' => $match->durability,
                ],
            );
        }
    }

    public function delete(string $systemId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM coating_system_compliance WHERE system_id = :id',
            ['id' => $systemId],
        );
    }
}
```

- [ ] **Step 4: Run — PASS**

- [ ] **Step 5: Test rewrite удаляет старые строки**

```php
public function test_rewrite_removes_stale_rows(): void
{
    $system = $this->buildZincRichEpSystemAndPersist();
    $this->repo->rewrite($system, $this->evaluator);
    $countBefore = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM coating_system_compliance WHERE system_id = ?', [$system->getId()]);
    self::assertGreaterThan(0, $countBefore);

    // Мутируем систему так, чтобы compliance изменился (например, снять zinc-rich)
    // Или проще — прогнать rewrite ещё раз, число не должно удвоиться.
    $this->repo->rewrite($system, $this->evaluator);
    $countAfter = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM coating_system_compliance WHERE system_id = ?', [$system->getId()]);
    self::assertSame($countBefore, $countAfter);
}
```

---

## Task 10: RefreshCacheOnCoatingSystemMutatedHandler

**Files:**
- Create: `app/src/Coatings/Application/Event/RefreshCacheOnCoatingSystemMutatedHandler.php`
- Test: `app/tests/Functional/Coatings/Application/Event/RefreshCacheOnCoatingSystemMutatedHandlerTest.php`

**Interfaces:**
- Consumes: `EventHandlerInterface`, `CoatingSystemMutated` (Task 1), обе cache-репы (Task 8, 9), `CoatingSystemRepositoryInterface`, `ComplianceEvaluator`.
- Produces: subscriber, регистрируется через `_instanceof EventHandlerInterface` → tag `messenger.message_handler bus: event.bus`.

- [ ] **Step 1: Failing test — dispatch → tables updated**

```php
public function test_dispatch_updates_both_cache_tables(): void
{
    $system = $this->buildZincRichEpSystemAndPersist();
    // очистить кэш если что-то там лежит
    $this->searchCache->delete($system->getId());
    $this->complianceCache->delete($system->getId());

    // dispatch через event.bus (sync)
    $this->eventBus->execute(new CoatingSystemMutated($system->getId()));

    $searchRow = $this->conn->fetchAssociative(
        'SELECT * FROM coating_system_search WHERE system_id = ?', [$system->getId()],
    );
    self::assertNotFalse($searchRow);

    $complianceCount = (int) $this->conn->fetchOne(
        'SELECT COUNT(*) FROM coating_system_compliance WHERE system_id = ?', [$system->getId()],
    );
    self::assertGreaterThan(0, $complianceCount);
}
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Реализация**

```php
<?php declare(strict_types=1);
namespace App\Coatings\Application\Event;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use App\Coatings\Domain\Event\CoatingSystemMutated;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Infrastructure\Cache\CoatingSystemComplianceCacheRepository;
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
use App\Shared\Application\Event\EventHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RefreshCacheOnCoatingSystemMutatedHandler implements EventHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private CoatingSystemSearchCacheRepository $searchCache,
        private CoatingSystemComplianceCacheRepository $complianceCache,
        private ComplianceEvaluator $evaluator,
    ) {
    }

    public function __invoke(CoatingSystemMutated $event): void
    {
        $system = $this->repo->findById(Uuid::fromString($event->systemId));
        if (null === $system) {
            return;   // система удалена — cascade FK почистит кэш; ничего не делать
        }
        $this->searchCache->upsert($system);
        $this->complianceCache->rewrite($system, $this->evaluator);
    }
}
```

- [ ] **Step 4: Run — PASS**

---

## Task 11: RefreshCacheOnCoatingMutatedHandler + findByLayerCoatingId

**Files:**
- Modify: `app/src/Coatings/Domain/Repository/CoatingSystemRepositoryInterface.php`
- Modify: `app/src/Coatings/Infrastructure/Repository/CoatingSystemRepository.php`
- Create: `app/src/Coatings/Application/Event/RefreshCacheOnCoatingMutatedHandler.php`
- Test: `app/tests/Functional/Coatings/Application/Event/RefreshCacheOnCoatingMutatedHandlerTest.php`

**Interfaces:**
- Produces:
  - `CoatingSystemRepositoryInterface::findByLayerCoatingId(string $coatingId): array<CoatingSystem>` — новый метод.
  - Subscriber `RefreshCacheOnCoatingMutatedHandler` — принимает `CoatingMutated`, перебирает системы, для каждой вызывает те же upsert/rewrite.

- [ ] **Step 1: Failing test для findByLayerCoatingId**

```php
public function test_findByLayerCoatingId_returns_systems_containing_that_coating(): void
{
    $coating = $this->makeCoatingAndPersist();
    $systemA = $this->makeSystemWithLayer($coating);
    $systemB = $this->makeSystemWithoutThatLayer();

    $found = $this->repo->findByLayerCoatingId($coating->getId());

    self::assertCount(1, $found);
    self::assertSame($systemA->getId(), $found[0]->getId());
}
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Расширить интерфейс**

`CoatingSystemRepositoryInterface`:
```php
/** @return list<CoatingSystem> */
public function findByLayerCoatingId(string $coatingId): array;
```

`CoatingSystemRepository` (реализация):
```php
public function findByLayerCoatingId(string $coatingId): array
{
    return $this->createQueryBuilder('cs')
        ->innerJoin('cs.layers', 'l')
        ->where('l.coating = :coatingId')
        ->setParameter('coatingId', $coatingId)
        ->getQuery()
        ->getResult();
}
```

- [ ] **Step 4: Run — PASS**

- [ ] **Step 5: Failing test для subscriber-a `CoatingMutated`**

```php
public function test_dispatch_updates_all_systems_with_that_coating(): void
{
    $coating = $this->makeCoatingAndPersist();
    $sysA = $this->buildSystemWithLayer($coating);
    $sysB = $this->buildSystemWithLayer($coating);

    $this->eventBus->execute(new CoatingMutated($coating->getId()));

    foreach ([$sysA, $sysB] as $sys) {
        $row = $this->conn->fetchAssociative(
            'SELECT * FROM coating_system_search WHERE system_id = ?', [$sys->getId()],
        );
        self::assertNotFalse($row);
    }
}
```

- [ ] **Step 6: Реализовать subscriber**

```php
<?php declare(strict_types=1);
namespace App\Coatings\Application\Event;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use App\Coatings\Domain\Event\CoatingMutated;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Infrastructure\Cache\CoatingSystemComplianceCacheRepository;
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
use App\Shared\Application\Event\EventHandlerInterface;

final readonly class RefreshCacheOnCoatingMutatedHandler implements EventHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private CoatingSystemSearchCacheRepository $searchCache,
        private CoatingSystemComplianceCacheRepository $complianceCache,
        private ComplianceEvaluator $evaluator,
    ) {
    }

    public function __invoke(CoatingMutated $event): void
    {
        foreach ($this->repo->findByLayerCoatingId($event->coatingId) as $system) {
            $this->searchCache->upsert($system);
            $this->complianceCache->rewrite($system, $this->evaluator);
        }
    }
}
```

- [ ] **Step 7: Run — PASS**

---

## Task 12: Delete legacy projector + tests

**Files:**
- Delete: `app/src/Coatings/Infrastructure/Projector/CoatingSystemSearchProjector.php`
- Delete: `app/tests/Functional/Coatings/Infrastructure/Projector/CoatingSystemSearchProjectorTest.php`
- Modify: `app/config/services.yaml` — снять регистрацию `CoatingSystemSearchProjector: ~`.

- [ ] **Step 1: Проверить что нет других ссылок**

```bash
grep -rn "CoatingSystemSearchProjector" app/src app/tests app/config
```

Ожидаемо: только регистрация в `services.yaml` и файл-класс сам.

- [ ] **Step 2: Удалить оба файла + строку из services.yaml**

- [ ] **Step 3: Прогон unit + functional**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Coatings tests/Functional/Coatings
```

Ожидаемо: PASS (subscriber-ы теперь заменяют проектор в flow).

- [ ] **Step 4: phpstan + cs-fixer clean**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/php-cs-fixer fix --dry-run --diff
```

---

## Task 13: Rebuild command (backfill)

**Files:**
- Create: `app/src/Coatings/Infrastructure/Console/RebuildCoatingSystemSearchCacheCommand.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Console/RebuildCoatingSystemSearchCacheCommandTest.php`

**Interfaces:**
- Consumes: `CoatingSystemRepositoryInterface`, `CoatingSystemSearchCacheRepository`, `CoatingSystemComplianceCacheRepository`, `ComplianceEvaluator`.
- Produces: консольная команда `app:coating-system:rebuild-search-cache`. Итерирует все системы, для каждой вызывает `searchCache->upsert` + `complianceCache->rewrite`.

- [ ] **Step 1: Failing test**

```php
public function test_command_backfills_both_cache_tables_for_all_systems(): void
{
    // Создать 2 системы, кэш пустой
    $sysA = $this->buildSystemAndPersist();
    $sysB = $this->buildSystemAndPersist();
    $this->conn->executeStatement('DELETE FROM coating_system_search');
    $this->conn->executeStatement('DELETE FROM coating_system_compliance');

    $tester = new CommandTester(
        static::getContainer()->get(RebuildCoatingSystemSearchCacheCommand::class),
    );
    $tester->execute([]);

    foreach ([$sysA, $sysB] as $s) {
        self::assertNotFalse($this->conn->fetchAssociative(
            'SELECT * FROM coating_system_search WHERE system_id = ?', [$s->getId()],
        ));
    }
    self::assertSame(0, $tester->getStatusCode());
}
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Реализация**

```php
<?php declare(strict_types=1);
namespace App\Coatings\Infrastructure\Console;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Infrastructure\Cache\CoatingSystemComplianceCacheRepository;
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:coating-system:rebuild-search-cache', description: 'Backfill: пересобрать coating_system_search и coating_system_compliance для всех систем.')]
final class RebuildCoatingSystemSearchCacheCommand extends Command
{
    public function __construct(
        private readonly CoatingSystemRepositoryInterface $repo,
        private readonly CoatingSystemSearchCacheRepository $searchCache,
        private readonly CoatingSystemComplianceCacheRepository $complianceCache,
        private readonly ComplianceEvaluator $evaluator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = 0;
        foreach ($this->repo->findAll() as $system) {
            $this->searchCache->upsert($system);
            $this->complianceCache->rewrite($system, $this->evaluator);
            ++$count;
        }
        $io->success(sprintf('Пересобрано систем: %d', $count));
        return Command::SUCCESS;
    }
}
```

Примечание: если `findAll()` нет в интерфейсе — добавить (см. существующие методы репо; возможно уже есть).

- [ ] **Step 4: Run — PASS**

- [ ] **Step 5: Прогон всей тест-сюиты**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Coatings tests/Functional/Coatings
```

Ожидаемо: 0 ошибок, 0 файлов на правку, все тесты зелёные.

---

## Self-review (проведён при написании)

**Coverage** — все секции спеки покрыты:
- Секция 1 (Domain) → Tasks 4, 5, 6.
- Секция 2 (Events + subscribers) → Tasks 1, 10, 11.
- Секция 3 (Cache tables + repos) → Tasks 7, 8, 9.
- Секция 4 (Handler-ы + cleanup) → Task 6, 12.
- Секция 5 (Транзиция) → Tasks 7, 13.

**Type consistency** — сигнатуры согласованы:
- `evaluate(): ComplianceMatches` (Task 3) → используется в `complianceMatches($evaluator)` (Task 4) и в `ComplianceCacheRepository::rewrite` (Task 9).
- `findByLayerCoatingId(string): array<CoatingSystem>` (Task 11) → используется в `RefreshCacheOnCoatingMutatedHandler` (Task 11).
- Событие `CoatingSystemMutated::systemId` (Task 1) → `RefreshCacheOnCoatingSystemMutatedHandler` (Task 10).

**Placeholder scan** — TBD/TODO нет; конкретные методы, файлы, SQL приведены.
