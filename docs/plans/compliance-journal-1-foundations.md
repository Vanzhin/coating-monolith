# Compliance D1 — Фундамент нормы: стабильный id позиции + режим инвентарная/расходная Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Дать каждой позиции требования стабильный `id`, а материальной позиции — режим (инвентарная/расходная ДСИЗ), и заставить светофор считать расходные позиции по дате (игнорируя остаток).

**Architecture:** Аддитивный деплой поверх текущей модели (акты выдачи ещё живы). `id` и `mode` хранятся внутри jsonb-позиции (`compliance_requirement.items`) — DBAL-тип и ORM-маппинг не меняются. `mode` доносится до единого резолвера статуса через проекцию `TrackedObligation` (новая nullable-колонка). Ключ обязанности/факта остаётся label-базовым — перевод на `id` это D3, здесь НЕ трогаем.

**Tech Stack:** PHP 8.5, Symfony, Doctrine ORM (XML-маппинг) + DBAL custom JsonType, PHPUnit, Twig, Stimulus. Домены DDD/Hexagonal.

**Spec:** `docs/plans/compliance-journal-model-design.md` (разделы §3.4, §4.1, §4.4; этот план = деплой D1 из §9).

## Global Constraints

- VO — `final readonly`; доменные ошибки — `App\Shared\Infrastructure\Exception\AppException` с человекочитаемым русским сообщением.
- **id передаётся в конструктор, не генерится в VO** (feedback: id-passed-not-generated). Генерация нового id — в Application (`RequirementItemBuilder`). `fromArray` при гидрации легаси-строки без id генерит как back-compat fallback (документировать).
- **Статус считает ТОЛЬКО `ComplianceStatusResolver`** — второй ладдер не плодить (feedback: compliance-status-single-source). `DUE_SOON_DAYS = 60` не трогать.
- **Ключ обязанности остаётся label-базовым** (`TrackedObligation::keyOf(requirementId, label)`). В D1 его НЕ переводим на id (это D3). Не трогать `keyOf`, `excluded`, `keysWithFacts`, `removeObligationByKey`.
- Миграции идемпотентные (`ADD COLUMN IF NOT EXISTS` / `DROP COLUMN IF EXISTS`), имя версии — позже последней существующей (`Version20261017120000` на момент написания; при реализации свериться и взять бо́льшую метку).
- Прод НЕ пуст: 3 требования, их позиции без ключей `id`/`mode`. Гидрация обязана не падать; `mode` по умолчанию `inventoried`; `id` бэкфиллится один раз.
- Гейт — `./run check` зелёный (style/phpstan/unit/functional), не только phpunit (feedback: sdd-run-check-gates). Unit — на хосте (`cd app && vendor/bin/phpunit tests/Unit/...`), functional — в контейнере, cs-fixer/phpstan — в контейнере (host PHP 8.5 новее).
- После правки Twig/JS — `cd app && yarn dev`; верификация фронта — сборка+браузер, не phpunit (feedback: no-phpunit-on-frontend).
- Enum vs bool для режима — решено: **enum `MaterialMode`** (расширяемее, паттерн `ComplianceType`). Закрывает открытый пункт §10 спеки.

## Review Focus

- **Легаси jsonb-позиции без `id`/`mode`** (3 требования на проде): гидрация не падает, `mode`→inventoried, `id` стабилен после бэкфилла. → Task 2 (fromArray-тест), Task 3 (default-тест), Task 8 (бэкфилл).
- **Правка требования сохраняет id существующих позиций** (edit round-trip не теряет и не перегенерирует id). → Task 5 (functional).
- **Расходник с held < норма, но выдан в срок → Ok, НЕ Missing** (иначе ДСИЗ всегда красный). → Task 7 (resolver-тест).
- **Расходник просрочен по дате → Overdue** (дата работает без остатка). → Task 7 (resolver-тест).
- **Нематериальная позиция без режима не ломает резолвер** (mode=null, held-gate и так пропускается). → Task 7 (resolver-тест, non-material).

---

## File Structure

- Create: `app/src/Compliance/Domain/Type/MaterialMode.php` — enum режима материальной позиции.
- Modify: `app/src/Compliance/Domain/ValueObject/Item/RequirementItemInterface.php` — метод `id()`.
- Modify: `app/src/Compliance/Domain/ValueObject/Item/AbstractRequirementItem.php` — поле/ctor `id`, `baseArray()`.
- Modify: `app/src/Compliance/Domain/ValueObject/Item/MaterialItem.php` — ctor `id`+`mode`, `mode()`, json/fromArray.
- Modify: `app/src/Compliance/Domain/ValueObject/Item/NonMaterialItem.php` — ctor `id`, fromArray.
- Modify: `app/src/Compliance/Application/Service/RequirementItemBuilder.php` — генерация/сохранение id, парсинг mode.
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligation.php` — поле `mode`, ctor, `mode()`/`isConsumable()`.
- Modify: `app/src/Compliance/Infrastructure/Database/ORM/Aggregate/ProfileCompliance.TrackedObligation.orm.xml` — колонка `mode`.
- Modify: `app/src/Compliance/Application/Service/ComplianceProjectionRebuilder.php` — прокинуть mode в `new TrackedObligation`.
- Modify: `app/src/Compliance/Domain/Service/ComplianceStatusResolver.php` — параметр `consumable`, ветка.
- Modify (5 call-sites резолвера): `app/src/Compliance/Application/DTO/ProfileCompliance/ProfileComplianceDTOTransformer.php`, `app/src/Compliance/Application/DTO/Dashboard/PersonRowDTOTransformer.php`, `app/src/Compliance/Application/Service/DueSoonScanner.php`, `app/src/Compliance/Application/Service/DraftFormationService.php`, `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php` (`worstStatus`).
- Modify: `app/src/Compliance/Application/DTO/Requirement/RequirementItemDTO.php` + `RequirementDTOTransformer.php` — поля id/mode.
- Modify: `app/src/Compliance/Application/UseCase/Command/SaveRequirement/SaveRequirementCommand.php` — phpstan-type.
- Modify: `app/src/Compliance/Infrastructure/Controller/Requirements/EditAction.php` — проброс id/mode в inputData.
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/requirements/_macros.html.twig` + `form.html.twig` — hidden id + select mode.
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version<ts>.php` — колонка `compliance_tracked_obligation.mode`.
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version<ts+1>.php` — бэкфилл id в jsonb существующих требований.
- Tests (create/modify): `tests/Unit/Compliance/Domain/Type/MaterialModeTest.php`, `.../Item/MaterialItemTest.php`, `.../Item/NonMaterialItemTest.php`, `.../Service/RequirementItemBuilderTest.php`, `.../Service/ComplianceStatusResolverTest.php`, `tests/Functional/Compliance/Application/UseCase/RequirementUseCasesTest.php`.

---

### Task 1: Enum `MaterialMode`

**Files:**
- Create: `app/src/Compliance/Domain/Type/MaterialMode.php`
- Test: `app/tests/Unit/Compliance/Domain/Type/MaterialModeTest.php`

**Interfaces:**
- Produces: `enum MaterialMode: string { Inventoried='inventoried'; Consumable='consumable'; }` c `title(): string`, `isConsumable(): bool`, `writeOffApplicable(): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Type;

use App\Compliance\Domain\Type\MaterialMode;
use PHPUnit\Framework\TestCase;

final class MaterialModeTest extends TestCase
{
    public function test_values_and_titles(): void
    {
        self::assertSame('inventoried', MaterialMode::Inventoried->value);
        self::assertSame('consumable', MaterialMode::Consumable->value);
        self::assertSame('инвентарная', MaterialMode::Inventoried->title());
        self::assertSame('расходная', MaterialMode::Consumable->title());
    }

    public function test_consumable_predicate_and_writeoff_applicability(): void
    {
        self::assertFalse(MaterialMode::Inventoried->isConsumable());
        self::assertTrue(MaterialMode::Inventoried->writeOffApplicable());
        self::assertTrue(MaterialMode::Consumable->isConsumable());
        self::assertFalse(MaterialMode::Consumable->writeOffApplicable());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Type/MaterialModeTest.php`
Expected: FAIL — класс `MaterialMode` не существует.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Type;

/**
 * Режим материальной позиции. Инвентарная (обычные СИЗ): ведётся остаток, контроль по остатку vs норма,
 * подлежит списанию. Расходная (ДСИЗ — крема/мыло): остаток не ведём, контроль по дате (как инструктаж),
 * списанию не подлежит. Количество (Quantity) есть у обеих — режим управляет только учётом остатка и списанием.
 * Новый режим = новый case; ядро не трогаем (паттерн {@see ComplianceType}).
 */
enum MaterialMode: string
{
    case Inventoried = 'inventoried';
    case Consumable = 'consumable';

    public function title(): string
    {
        return match ($this) {
            self::Inventoried => 'инвентарная',
            self::Consumable => 'расходная',
        };
    }

    /** Остаток не ведём, контроль по дате. */
    public function isConsumable(): bool
    {
        return self::Consumable === $this;
    }

    /** Подлежит списанию (акт списания) только инвентарная. */
    public function writeOffApplicable(): bool
    {
        return self::Inventoried === $this;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Type/MaterialModeTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/src/Compliance/Domain/Type/MaterialMode.php app/tests/Unit/Compliance/Domain/Type/MaterialModeTest.php
git commit -m "Compliance: enum MaterialMode (инвентарная/расходная) — режим материальной позиции"
```

---

### Task 2: Стабильный `id` у позиции требования

**Files:**
- Modify: `app/src/Compliance/Domain/ValueObject/Item/RequirementItemInterface.php`
- Modify: `app/src/Compliance/Domain/ValueObject/Item/AbstractRequirementItem.php`
- Modify: `app/src/Compliance/Domain/ValueObject/Item/MaterialItem.php:14-19,34-43` (ctor + fromArray)
- Modify: `app/src/Compliance/Domain/ValueObject/Item/NonMaterialItem.php:27-35` (fromArray)
- Test: `app/tests/Unit/Compliance/Domain/ValueObject/Item/MaterialItemTest.php`, `.../NonMaterialItemTest.php`

**Interfaces:**
- Produces: `RequirementItemInterface::id(): string`. Конструкторы позиций принимают `id` первым параметром: `new MaterialItem(string $id, string $label, Cadence $cadence, string $basis, Quantity $quantity)`, `new NonMaterialItem(string $id, string $label, Cadence $cadence, string $basis)`. `baseArray()`/`jsonSerialize()` включают ключ `id`; `fromArray()` читает `id`, при отсутствии — генерит (back-compat).

- [ ] **Step 1: Write the failing test** — обнови `MaterialItemTest` (хелпер `item()` передаёт id + два новых теста):

```php
    private function item(): MaterialItem
    {
        return new MaterialItem(
            'itm-1',
            'Перчатки х/б',
            new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            'п.5 Типовых норм',
            new Quantity(10.0, Unit::Pair),
        );
    }

    public function test_exposes_stable_id(): void
    {
        self::assertSame('itm-1', $this->item()->id());
        self::assertArrayHasKey('id', $this->item()->jsonSerialize());
        self::assertSame('itm-1', $this->item()->jsonSerialize()['id']);
    }

    public function test_from_array_without_id_generates_one(): void
    {
        $data = $this->item()->jsonSerialize();
        unset($data['id']);
        $rebuilt = MaterialItem::fromArray($data);

        self::assertNotSame('', $rebuilt->id());
    }
```
(Хелпер и `test_blank_label_rejected`/`test_blank_basis_rejected` в этом файле тоже обнови: добавь `'itm-x'` первым аргументом в их прямые `new MaterialItem(...)`.) Аналогично заведи/обнови `NonMaterialItemTest` с `id` первым аргументом и тестом `test_exposes_stable_id`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/ValueObject/Item`
Expected: FAIL — слишком мало аргументов конструктора / нет метода `id()`.

- [ ] **Step 3: Implement — интерфейс**

В `RequirementItemInterface` добавь первым методом:
```php
    public function id(): string;
```

- [ ] **Step 4: Implement — `AbstractRequirementItem`**

Добавь поле и прими id первым параметром ctor; включи в `baseArray()`:
```php
    public const MAX_LABEL_LENGTH = 1000;

    private string $id;
    private string $label;
    private string $basis;

    public function __construct(string $id, string $label, private Cadence $cadence, string $basis)
    {
        $id = trim($id);
        if ('' === $id) {
            throw new AppException('Внутренняя ошибка: у позиции требования нет идентификатора.');
        }
        $this->id = $id;

        $label = trim($label);
        // ... существующая валидация label/basis без изменений ...
    }

    public function id(): string
    {
        return $this->id;
    }

    protected function baseArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'cadence' => $this->cadence->jsonSerialize(),
            'basis' => $this->basis,
        ];
    }
```

- [ ] **Step 5: Implement — `MaterialItem` и `NonMaterialItem`**

`MaterialItem` ctor и `fromArray` (генерация id — back-compat для легаси jsonb без ключа; основной путь передаёт id):
```php
    public function __construct(string $id, string $label, Cadence $cadence, string $basis, private Quantity $quantity)
    {
        parent::__construct($id, $label, $cadence, $basis);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            self::readId($data),
            (string) ($data['label'] ?? ''),
            Cadence::fromArray(is_array($data['cadence'] ?? null) ? $data['cadence'] : []),
            (string) ($data['basis'] ?? ''),
            Quantity::fromArray(is_array($data['quantity'] ?? null) ? $data['quantity'] : []),
        );
    }
```
В `AbstractRequirementItem` добавь защищённый хелпер (back-compat: легаси-строки до бэкфилла; builder/новые всегда с id):
```php
    /** @param array<string, mixed> $data */
    protected static function readId(array $data): string
    {
        $id = trim((string) ($data['id'] ?? ''));

        return '' !== $id ? $id : Uuid::v7()->toRfc4122();
    }
```
(импорт `use Symfony\Component\Uid\Uuid;`). Аналогично `NonMaterialItem::fromArray` — `self::readId($data)` первым аргументом. `jsonSerialize()` обоих уже вернут `id` через обновлённый `baseArray()`.

- [ ] **Step 6: Run tests**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/ValueObject/Item`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/src/Compliance/Domain/ValueObject/Item app/tests/Unit/Compliance/Domain/ValueObject/Item
git commit -m "Compliance: у позиции требования появился стабильный id (jsonb), ключ обязанности пока прежний"
```

---

### Task 3: Режим `mode` на `MaterialItem`

**Files:**
- Modify: `app/src/Compliance/Domain/ValueObject/Item/MaterialItem.php`
- Test: `app/tests/Unit/Compliance/Domain/ValueObject/Item/MaterialItemTest.php`

**Interfaces:**
- Consumes: `MaterialMode` (Task 1).
- Produces: `MaterialItem::mode(): MaterialMode`; ctor добавляет `MaterialMode $mode = MaterialMode::Inventoried` последним параметром; json/fromArray несут `mode` (default inventoried).

- [ ] **Step 1: Write the failing test** (добавь в `MaterialItemTest`):

```php
    public function test_default_mode_is_inventoried(): void
    {
        self::assertSame(MaterialMode::Inventoried, $this->item()->mode());
    }

    public function test_mode_round_trips(): void
    {
        $consumable = new MaterialItem(
            'itm-2', 'Мыло', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Month),
            'п.X', new Quantity(1200.0, Unit::Milliliter), MaterialMode::Consumable,
        );
        $rebuilt = MaterialItem::fromArray($consumable->jsonSerialize());

        self::assertSame(MaterialMode::Consumable, $rebuilt->mode());
        self::assertSame('consumable', $consumable->jsonSerialize()['mode']);
    }

    public function test_legacy_json_without_mode_defaults_inventoried(): void
    {
        $data = $this->item()->jsonSerialize();
        unset($data['mode']);

        self::assertSame(MaterialMode::Inventoried, MaterialItem::fromArray($data)->mode());
    }
```
(Добавь `use App\Compliance\Domain\Type\MaterialMode;` и `use App\Compliance\Domain\ValueObject\Unit;` если не импортирован — `Unit::Milliliter` уже существует.)

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/ValueObject/Item/MaterialItemTest.php`
Expected: FAIL — нет метода `mode()` / слишком мало аргументов.

- [ ] **Step 3: Implement**

```php
    public function __construct(
        string $id,
        string $label,
        Cadence $cadence,
        string $basis,
        private Quantity $quantity,
        private MaterialMode $mode = MaterialMode::Inventoried,
    ) {
        parent::__construct($id, $label, $cadence, $basis);
    }

    public function mode(): MaterialMode
    {
        return $this->mode;
    }

    public function jsonSerialize(): array
    {
        return [...$this->baseArray(), 'quantity' => $this->quantity->jsonSerialize(), 'mode' => $this->mode->value];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            self::readId($data),
            (string) ($data['label'] ?? ''),
            Cadence::fromArray(is_array($data['cadence'] ?? null) ? $data['cadence'] : []),
            (string) ($data['basis'] ?? ''),
            Quantity::fromArray(is_array($data['quantity'] ?? null) ? $data['quantity'] : []),
            MaterialMode::tryFrom((string) ($data['mode'] ?? '')) ?? MaterialMode::Inventoried,
        );
    }
```
(импорт `use App\Compliance\Domain\Type\MaterialMode;`).

- [ ] **Step 4: Run tests**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/ValueObject/Item/MaterialItemTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/src/Compliance/Domain/ValueObject/Item/MaterialItem.php app/tests/Unit/Compliance/Domain/ValueObject/Item/MaterialItemTest.php
git commit -m "Compliance: у материальной позиции появился режим инвентарная/расходная (default инвентарная)"
```

---

### Task 4: `RequirementItemBuilder` — генерирует id, парсит mode

**Files:**
- Modify: `app/src/Compliance/Application/Service/RequirementItemBuilder.php`
- Test: `app/tests/Unit/Compliance/Application/Service/RequirementItemBuilderTest.php` (создать, если нет)

**Interfaces:**
- Consumes: `MaterialMode` (Task 1), поля позиции (Task 2/3).
- Produces: из строки формы собирает позицию с id (`$row['id']` если непуст, иначе новый `Uuid::v7()`) и, для материального, `mode` (`$row['mode']`, default inventoried).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Application\Service;

use App\Compliance\Application\Service\RequirementItemBuilder;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\MaterialMode;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use PHPUnit\Framework\TestCase;

final class RequirementItemBuilderTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Перчатки', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1',
            'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5',
        ], $overrides);
    }

    public function test_generates_id_when_absent(): void
    {
        $items = (new RequirementItemBuilder())->build(ComplianceType::Material, [$this->row()]);

        self::assertCount(1, $items);
        self::assertNotSame('', $items[0]->id());
    }

    public function test_preserves_existing_id(): void
    {
        $items = (new RequirementItemBuilder())->build(ComplianceType::Material, [$this->row(['id' => 'keep-me'])]);

        self::assertSame('keep-me', $items[0]->id());
    }

    public function test_parses_consumable_mode(): void
    {
        $items = (new RequirementItemBuilder())->build(ComplianceType::Material, [$this->row(['mode' => 'consumable'])]);

        self::assertInstanceOf(MaterialItem::class, $items[0]);
        self::assertSame(MaterialMode::Consumable, $items[0]->mode());
    }

    public function test_defaults_inventoried_when_mode_absent(): void
    {
        $items = (new RequirementItemBuilder())->build(ComplianceType::Material, [$this->row()]);

        self::assertInstanceOf(MaterialItem::class, $items[0]);
        self::assertSame(MaterialMode::Inventoried, $items[0]->mode());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Application/Service/RequirementItemBuilderTest.php`
Expected: FAIL — id пустой/перегенерён, mode не парсится.

- [ ] **Step 3: Implement** — в `RequirementItemBuilder::build()`, внутри цикла, перед сборкой `$data`:

```php
            $id = trim((string) ($row['id'] ?? ''));
            $data = [
                'id' => '' !== $id ? $id : Uuid::v7()->toRfc4122(),
                'label' => (string) $row['label'],
                'cadence' => $this->cadence($row),
                'basis' => (string) ($row['basis'] ?? ''),
            ];
            if ($type->requiresQuantity()) {
                $data['quantity'] = $this->quantity($row);
                $data['mode'] = (MaterialMode::tryFrom((string) ($row['mode'] ?? '')) ?? MaterialMode::Inventoried)->value;
            }
```
(импорты `use App\Compliance\Domain\Type\MaterialMode;` и `use Symfony\Component\Uid\Uuid;`).

- [ ] **Step 4: Run tests**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Application/Service/RequirementItemBuilderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/src/Compliance/Application/Service/RequirementItemBuilder.php app/tests/Unit/Compliance/Application/Service/RequirementItemBuilderTest.php
git commit -m "Compliance: билдер позиций генерит/сохраняет id и парсит режим расходная/инвентарная"
```

---

### Task 5: Форма требования — id (hidden) и mode (select), round-trip на правке

**Files:**
- Modify: `app/src/Compliance/Application/DTO/Requirement/RequirementItemDTO.php`
- Modify: `app/src/Compliance/Application/DTO/Requirement/RequirementDTOTransformer.php:34-51`
- Modify: `app/src/Compliance/Application/UseCase/Command/SaveRequirement/SaveRequirementCommand.php:16` (@phpstan-type)
- Modify: `app/src/Compliance/Infrastructure/Controller/Requirements/EditAction.php:58-66,110-143`
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/requirements/_macros.html.twig:15-63`
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/requirements/form.html.twig:107,109`
- Test: `app/tests/Functional/Compliance/Application/UseCase/RequirementUseCasesTest.php`

**Interfaces:**
- Consumes: `MaterialItem::id()/mode()` (Task 2/3), билдер (Task 4).
- Produces: форма несёт `items[i][id]` (hidden) и `items[i][mode]` (select); `RequirementItemDTO` имеет `id`,`mode`; edit сохраняет id и mode.

- [ ] **Step 1: Write the failing functional test** (добавь в `RequirementUseCasesTest`):

```php
    public function test_material_item_persists_mode_and_stable_id_across_edit(): void
    {
        $id = $this->createRequirement(ComplianceType::Material, [
            $this->materialItem(['label' => 'Мыло', 'mode' => 'consumable', 'unit' => 'milliliter', 'amount' => '1200']),
        ]);

        $dto = $this->queryBus->execute(new GetRequirementQuery($id));
        self::assertCount(1, $dto->items);
        $originalItemId = $dto->items[0]->id;
        self::assertNotSame('', $originalItemId);
        self::assertSame('consumable', $dto->items[0]->mode);

        // правка: имя меняем, id в форме возвращаем — id обязан сохраниться
        $this->commandBus->dispatch(new SaveRequirementCommand(
            $id, ComplianceType::Material->value, 'Норма', ['pos-1'],
            [$this->materialItem(['id' => $originalItemId, 'label' => 'Мыло жидкое', 'mode' => 'consumable', 'unit' => 'milliliter', 'amount' => '1200'])],
            null, false, null,
        ));

        $this->em()->clear();
        $reloaded = $this->queryBus->execute(new GetRequirementQuery($id));
        self::assertSame($originalItemId, $reloaded->items[0]->id);
        self::assertSame('Мыло жидкое', $reloaded->items[0]->label);
        self::assertSame('consumable', $reloaded->items[0]->mode);
    }
```
(Сверь точную сигнатуру `SaveRequirementCommand` и хелперов `createRequirement`/`materialItem`/`em()` с существующим файлом теста и подгони аргументы под фактические — остальные тесты в файле их уже используют.)

- [ ] **Step 2: Run test to verify it fails**

Run (в контейнере, как остальные functional): `./run console` недоступен для phpunit — используй проектный способ functional-прогона, напр. `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Functional/Compliance/Application/UseCase/RequirementUseCasesTest.php` (сверь с README/CLAUDE тестовую команду).
Expected: FAIL — `RequirementItemDTO` не имеет `id`/`mode`.

- [ ] **Step 3: Implement — DTO + transformer**

В `RequirementItemDTO` добавь публичные поля `public string $id` и `public string $mode` (для нематериального `mode=''`). В `RequirementDTOTransformer::itemDto()`:
```php
        $dto->id = $item->id();
        $dto->mode = $item instanceof MaterialItem ? $item->mode()->value : '';
```
(импорт `use App\Compliance\Domain\ValueObject\Item\MaterialItem;` если нет).

- [ ] **Step 4: Implement — command phpstan-type + EditAction**

В `SaveRequirementCommand` расширь `@phpstan-type ItemInput` ключами `id?: string, mode?: string`. В `EditAction::loadInput()` (ветка сбора items из DTO, строки ~130-141) добавь в собираемый массив строки `'id' => $item->id, 'mode' => $item->mode`. В ветке ре-рендера после ошибки (`'items' => $items`) ничего не меняется — `$items` уже сырой ввод формы с `id`/`mode`.

- [ ] **Step 5: Implement — Twig**

В `_macros.html.twig::item_row` добавь скрытый id сразу после открытия `row`-дива:
```twig
        <input type="hidden" name="items[{{ i }}][id]" value="{{ item.id|default('') }}">
```
И блок режима внутри строки количества (прячется для нематериальных тем же `data-item-quantity`, что и количество):
```twig
            <div class="col-md-4" data-item-quantity>
                <label class="form-label small mb-1">Режим</label>
                <select name="items[{{ i }}][mode]" class="form-select">
                    {% for mm in materialModes %}<option value="{{ mm.value }}" {{ (item.mode|default('inventoried')) == mm.value ? 'selected' : '' }}>{{ mm.title }}</option>{% endfor %}
                </select>
            </div>
```
Расширь сигнатуру макроса до `item_row(i, item, cadenceKinds, periodUnits, units, materialModes)` и обнови оба вызова в `form.html.twig` (строки 107 и 109), прокинув `materialModes`. В `EditAction` (где рендерится форма) передай в шаблон `'materialModes' => MaterialMode::cases()`.

- [ ] **Step 6: Rebuild assets + run test**

Run: `cd app && yarn dev`
Run: functional-прогон из шага 2.
Expected: PASS. Проверь форму в браузере: у материального требования виден select «Режим», у нематериального скрыт; правка сохраняет режим.

- [ ] **Step 7: Commit**

```bash
git add app/src/Compliance/Application/DTO/Requirement app/src/Compliance/Application/UseCase/Command/SaveRequirement app/src/Compliance/Infrastructure/Controller/Requirements/EditAction.php app/src/Shared/Infrastructure/Templates/admin/compliance/requirements app/tests/Functional/Compliance/Application/UseCase/RequirementUseCasesTest.php
git commit -m "Compliance: в форме требования выбор режима позиции + скрытый id, правка сохраняет id и режим"
```

---

### Task 6: Проекция `TrackedObligation` несёт `mode`

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligation.php:23-60` (поле/ctor/getter)
- Modify: `app/src/Compliance/Infrastructure/Database/ORM/Aggregate/ProfileCompliance.TrackedObligation.orm.xml`
- Modify: `app/src/Compliance/Application/Service/ComplianceProjectionRebuilder.php:114-131`
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version<ts>.php`
- Test: `app/tests/Functional/Compliance/Application/UseCase/` (где уже тестируется rebuild) — проверка, что mode проставился.

**Interfaces:**
- Consumes: `MaterialMode` (Task 1), `MaterialItem::mode()` (Task 3).
- Produces: `TrackedObligation` имеет `?MaterialMode $mode` (null для нематериального) + `mode(): ?MaterialMode`, `isConsumable(): bool`; ctor принимает `$mode` новым параметром (после `$quantity`, перед `$departmentId` или в конце с дефолтом null — см. ниже).

- [ ] **Step 1: Write the failing test** — unit-тест `TrackedObligation` (создать `tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligationTest.php`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\MaterialMode;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class TrackedObligationTest extends TestCase
{
    public function test_consumable_mode_is_exposed(): void
    {
        $o = new TrackedObligation(
            Uuid::v7(), $this->createStub(ProfileCompliance::class), 'req-1', 'Норма', 'Мыло',
            ComplianceType::Material, new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Month),
            new Quantity(1200.0, Unit::Milliliter), 'dep-1', TrackedObligation::ORIGIN_NORM, MaterialMode::Consumable,
        );

        self::assertSame(MaterialMode::Consumable, $o->mode());
        self::assertTrue($o->isConsumable());
    }

    public function test_non_material_has_no_mode(): void
    {
        $o = new TrackedObligation(
            Uuid::v7(), $this->createStub(ProfileCompliance::class), 'req-2', 'Инструктаж', 'ТБ',
            ComplianceType::NonMaterial, new Cadence(CadenceKind::Once, null, null),
            null, 'dep-1', TrackedObligation::ORIGIN_NORM, null,
        );

        self::assertNull($o->mode());
        self::assertFalse($o->isConsumable());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligationTest.php`
Expected: FAIL — конструктор не принимает mode / нет `mode()`.

- [ ] **Step 3: Implement — агрегат**

В `TrackedObligation`: добавь поле `private ?MaterialMode $mode = null;`, параметр ctor `?MaterialMode $mode = null` последним (после `$origin`), присвой; добавь геттеры:
```php
    public function mode(): ?MaterialMode
    {
        return $this->mode;
    }

    public function isConsumable(): bool
    {
        return null !== $this->mode && $this->mode->isConsumable();
    }
```
(импорт `use App\Compliance\Domain\Type\MaterialMode;`).

- [ ] **Step 4: Implement — ORM + миграция**

В `ProfileCompliance.TrackedObligation.orm.xml` после поля `origin` добавь:
```xml
        <field name="mode" type="string" length="16" column="mode" nullable="true"
               enum-type="App\Compliance\Domain\Type\MaterialMode"/>
```
Создай миграцию (метку свериться — позже последней существующей):
```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: compliance_tracked_obligation.mode (nullable) — режим материальной позиции на проекции
 * (инвентарная/расходная). Null у нематериальных. Проекция пересобирается, бэкофилл не нужен — mode встанет
 * при следующей пересборке (app:compliance:rebuild-projection).
 */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: compliance_tracked_obligation.mode (nullable) — режим позиции на проекции.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_tracked_obligation ADD COLUMN IF NOT EXISTS mode VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_tracked_obligation DROP COLUMN IF EXISTS mode');
    }
}
```

- [ ] **Step 5: Implement — rebuilder прокидывает mode**

В `ComplianceProjectionRebuilder` (конструирование `new TrackedObligation(...)`, строки ~122-127) добавь последний аргумент:
```php
                $obligation = new TrackedObligation(
                    Uuid::v7(), $profileCompliance, $requirement->getId(), $requirement->getName(),
                    $item->label(), $item->type(), $item->cadence(),
                    $item instanceof MaterialItem ? $item->quantity() : null,
                    $departmentId, TrackedObligation::ORIGIN_NORM,
                    $item instanceof MaterialItem ? $item->mode() : null,
                );
```
(`use App\Compliance\Domain\ValueObject\Item\MaterialItem;` уже импортирован.) Проверь другие места конструирования `TrackedObligation` (напр. `addPersonalObligation`) — там тоже добавить mode или оставить дефолт null.

- [ ] **Step 6: Apply migration + run tests**

Run: `cd app && bin/console doctrine:migrations:migrate -n` (dev; для test-БД — по проектной процедуре).
Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligationTest.php`
Expected: PASS. Прогони `app:compliance:rebuild-projection`, убедись что колонка `mode` заполняется.

- [ ] **Step 7: Commit**

```bash
git add app/src/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligation.php app/src/Compliance/Infrastructure/Database/ORM/Aggregate/ProfileCompliance.TrackedObligation.orm.xml app/src/Compliance/Application/Service/ComplianceProjectionRebuilder.php app/src/Shared/Infrastructure/Database/Migrations/Version20261020120000.php app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligationTest.php
git commit -m "Compliance: проекция обязанности несёт режим позиции (колонка mode), rebuilder его проставляет"
```

---

### Task 7: Резолвер — расходная позиция считается по дате (игнор остатка)

**Files:**
- Modify: `app/src/Compliance/Domain/Service/ComplianceStatusResolver.php`
- Modify (5 call-sites): `ProfileComplianceDTOTransformer.php:54-61`, `PersonRowDTOTransformer.php:41`, `DueSoonScanner.php:50-57`, `DraftFormationService.php:98`, `ProfileCompliance.php:700-721`
- Test: `app/tests/Unit/Compliance/Domain/Service/ComplianceStatusResolverTest.php`

**Interfaces:**
- Consumes: `TrackedObligation::isConsumable()` (Task 6).
- Produces: `bucketFor(...)` и `statusFor(...)` принимают новый параметр `bool $consumable = false` последним; при `true` пропускают проверку «held < norm».

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Service;

use App\Compliance\Domain\Service\ComplianceStatusResolver;
use App\Compliance\Domain\Type\ComplianceBucket;
use PHPUnit\Framework\TestCase;

final class ComplianceStatusResolverTest extends TestCase
{
    private ComplianceStatusResolver $resolver;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->resolver = new ComplianceStatusResolver();
        $this->now = new \DateTimeImmutable('2026-06-01');
    }

    public function test_inventoried_under_norm_is_missing(): void
    {
        // active, выдано недавно, но held(1) < norm(2), не расходник → Missing
        $b = $this->resolver->bucketFor(true, $this->now->modify('-10 days'), $this->now->modify('+300 days'), $this->now, 2.0, 1.0, false);
        self::assertSame(ComplianceBucket::Missing, $b);
    }

    public function test_consumable_under_norm_but_recent_is_ok(): void
    {
        // held(0) < norm(1200), но расходник и выдан в срок → остаток игнор, Ok
        $b = $this->resolver->bucketFor(true, $this->now->modify('-10 days'), $this->now->modify('+300 days'), $this->now, 1200.0, 0.0, true);
        self::assertSame(ComplianceBucket::Ok, $b);
    }

    public function test_consumable_overdue_by_date_is_overdue(): void
    {
        $b = $this->resolver->bucketFor(true, $this->now->modify('-400 days'), $this->now->modify('-1 day'), $this->now, 1200.0, 0.0, true);
        self::assertSame(ComplianceBucket::Overdue, $b);
    }

    public function test_non_material_unaffected_default_param(): void
    {
        // norm=null (нематериальное), consumable опущен → как раньше
        $b = $this->resolver->bucketFor(true, $this->now->modify('-10 days'), null, $this->now);
        self::assertSame(ComplianceBucket::Ok, $b);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Service/ComplianceStatusResolverTest.php`
Expected: FAIL — `test_consumable_under_norm_but_recent_is_ok` даёт Missing (held<norm не пропускается).

- [ ] **Step 3: Implement — резолвер**

Добавь `bool $consumable = false` последним параметром в `bucketFor` и `statusFor`; в `bucketFor` замени строку held-гейта:
```php
        if (!$consumable && null !== $norm && $held < $norm) {
            return ComplianceBucket::Missing; // недовыдано по количеству — требует выдачи (у расходника остаток не считаем)
        }
```
и прокинь в `statusFor`:
```php
        return ComplianceStatus::fromBucket($this->bucketFor($active, $lastFulfilledAt, $nextDueAt, $now, $norm, $held, $consumable));
```

- [ ] **Step 4: Implement — 5 call-sites**

В каждом добавь последним аргументом `$obligation->isConsumable()`:
- `ProfileComplianceDTOTransformer` (`statusFor(...)`) — после `$obligation->heldQuantity(),` добавь `$obligation->isConsumable(),`.
- `PersonRowDTOTransformer:41` — `..., $obligation->heldQuantity(), $obligation->isConsumable())`.
- `DueSoonScanner:50-57` — добавь `$obligation->isConsumable(),` последним.
- `DraftFormationService:98` — `$this->buckets->bucketFor(..., $obligation->heldQuantity(), $obligation->isConsumable())`.
- `ProfileCompliance::worstStatus` (`statusFor(...)`) — добавь `$obligation->isConsumable(),` последним.

- [ ] **Step 5: Run tests (весь контекст)**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/src/Compliance/Domain/Service/ComplianceStatusResolver.php app/src/Compliance/Application/DTO app/src/Compliance/Application/Service/DueSoonScanner.php app/src/Compliance/Application/Service/DraftFormationService.php app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php app/tests/Unit/Compliance/Domain/Service/ComplianceStatusResolverTest.php
git commit -m "Compliance: расходная позиция (ДСИЗ) считается по дате — остаток игнорируем, единый резолвер"
```

---

### Task 8: Бэкфилл id в jsonb существующих требований

**Files:**
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version<ts+1>.php`

**Interfaces:**
- Consumes: формат jsonb позиции с `id` (Task 2).
- Produces: все позиции существующих требований (прод: 3 требования) получают персистентный `id`; `mode` не бэкфиллим (default inventoried при чтении уже корректен).

- [ ] **Step 1: Write the migration (data)**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Compliance: бэкфилл стабильного id в позиции требований (jsonb compliance_requirement.items) для записей,
 * заведённых до появления id. Идемпотентно: позиции с уже проставленным id не трогаем. Прод — 3 требования.
 */
final class Version20261020120500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: бэкфилл id в позиции существующих требований (jsonb).';
    }

    public function up(Schema $schema): void
    {
        /** @var list<array{id: string, items: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, items FROM compliance_requirement');
        foreach ($rows as $row) {
            $items = json_decode((string) $row['items'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($items)) {
                continue;
            }
            $changed = false;
            foreach ($items as &$item) {
                if (is_array($item) && '' === trim((string) ($item['id'] ?? ''))) {
                    $item = ['id' => Uuid::v7()->toRfc4122()] + $item;
                    $changed = true;
                }
            }
            unset($item);
            if ($changed) {
                $this->connection->executeStatement(
                    'UPDATE compliance_requirement SET items = :items WHERE id = :id',
                    ['items' => json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'id' => $row['id']],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Необратимо (id — стабильные идентификаторы); откат не предусмотрен.
    }
}
```

- [ ] **Step 2: Apply migration**

Run: `cd app && bin/console doctrine:migrations:migrate -n`
Expected: миграция проходит; позиции 3 требований получают id.

- [ ] **Step 3: Verify backfill**

Run: `./run console dbal:run-sql "SELECT id, items FROM compliance_requirement"`
Expected: в каждом элементе `items` есть ключ `id`.

- [ ] **Step 4: Commit**

```bash
git add app/src/Shared/Infrastructure/Database/Migrations/Version20261020120500.php
git commit -m "Compliance: бэкфилл id в позиции существующих требований (идемпотентно)"
```

---

## Финальная проверка деплоя

- [ ] **Полный гейт:** `./run check` зелёный (style/phpstan/unit/functional).
- [ ] **Пересборка проекции:** `cd app && bin/console app:compliance:rebuild-projection` — колонка `mode` заполнена у материальных обязанностей.
- [ ] **Браузер:** форма требования — у материального виден select «Режим» (инвентарная/расходная), у нематериального скрыт; правка требования не теряет и не перегенерирует id позиций; расходная позиция на дашборде краснеет по дате, а не по нехватке остатка.
- [ ] **Ассеты:** `cd app && yarn dev` выполнен (правился Twig).

---

## Self-Review (выполнено при написании)

- **Spec coverage:** §3.4 (режим позиции, количество у обеих) → Task 1,3,5,7. §4.1 (стабильный id) → Task 2,4,5,8. §4.4 (ветвь резолвера остаток vs дата) → Task 7. §9 D1 полностью покрыт.
- **Placeholders:** нет — весь код приведён; места «сверь сигнатуру с существующим файлом» (Task 5) намеренны, т.к. фактические хелперы теста известны по имени, но подставляются из текущего файла.
- **Type consistency:** `MaterialMode` (Task 1) → `MaterialItem::mode()` (Task 3) → билдер (Task 4) → DTO.mode (Task 5) → `TrackedObligation::mode()/isConsumable()` (Task 6) → резолвер `$consumable`/call-sites (Task 7). `id()` (Task 2) → билдер/DTO (Task 4/5) → бэкфилл (Task 8). Ctor-порядок позиций (`id` первый, `mode` последний у Material) согласован между Task 2/3 и всеми конструкторами.
- **Review Focus:** 5 кейсов закрыты тестами Task 2/3/5/7/8 (см. раздел Review Focus).
- **Ключ обязанности:** во всех задачах оставлен label-базовым; переход на id вынесен в D3 (Global Constraints) — проверено, `keyOf`/excluded не трогаются.
