# Количественный учёт СИЗ (частичное списание + дефицит) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Списывать материальные СИЗ частями от выданного количества и держать соответствие норме по количеству: «на руках» (held), дефицит = норма − held, черновик выдачи на дефицит, статус краснит при held < норма.

**Architecture:** Факт выдачи (`FulfillmentRecord`) становится количественным item'ом (`quantity` выдано + `returnedQuantity` аккумулятор списанного); списание — дочерние **порции** (`WriteOffItem`: recordId+quantity+reason) у акта списания (`WriteOffAct`), эффект (returnedQuantity += порции + пересчёт) только на оформлении акта. Проекция `TrackedObligation` несёт денормализованный `heldQuantity` для дашборда; статус/бакет/формирование черновика/валидация выдачи становятся количественно-осознанными.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM (XML-маппинг), PostgreSQL (jsonb), PHPUnit 11. DDD+Hexagonal (`App\Compliance`). Шины команд/запросов через marker-интерфейсы.

**Spec:** `docs/plans/compliance-siz-6-quantity-writeoff.md`

## Global Constraints

- Домен-инварианты — в агрегате/VO (кидают `App\Shared\Infrastructure\Exception\AppException`, HTTP 422); Application только оркеструет.
- Команды реализуют `App\Shared\Application\Command\CommandHandlerInterface` (без `#[AsMessageHandler]`).
- Доступ — `App\Compliance\Application\Service\AccessControl\ComplianceAccessControl::canManage()` в хендлере.
- Количество — `float` (как `Quantity::$amount`); held/дефицит в тех же единицах, что норма позиции. held-логика — только у материальных позиций с нормой-`quantity`; нематериальные остаются датными.
- Списание адресно по факту-выдаче (`recordId`); статус/дефицit/черновик — на уровне позиции (`held` = Σ по фактам ключа).
- Миграции идемпотентные (`IF EXISTS`/`IF NOT EXISTS`); прод пуст — схему реформируем свободно, но релиз-гейт не должен падать.
- Тесты: unit на хосте (`cd app && vendor/bin/phpunit tests/Unit/...`); functional в контейнере (`docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Functional/...`). Тест-БД пересоздать+migrate при смене схемы: `docker compose -f docker-compose.test.yml rm -sfv test_db` затем `... run --rm test_php-cli bin/console doctrine:migrations:migrate -n`. dev-БД — дельтой через `docker compose exec -T manager_db psql`.
- Финальный гейт — `./run check` (style cs-fixer / phpstan level 6 / unit / functional) в контейнере; style:fix автофикс. Закладывать gate-cleanup (имплементеры пропускают style/phpstan).
- Бинарные `.docx`/`.xlsx` шаблоны НЕ трогать на этом шаге.
- Коммиты и пуш — только по явному апруву пользователя (feedback_no_commits); в SDD имплементер коммитит по задаче для ревью/леджера (feedback_sdd_commits).
- Отправная точка: текущий write-off = «переезд целиком» (на факте есть `writeOffActId`/`writeOffReason`, `WriteOffAct` — лёгкий заголовок без порций, `returnedQuantity` — `?Quantity`, есть `returned_at`). Этот план ПЕРЕИГРЫВАЕТ это на количественные порции.

## Review Focus

- **Списать больше, чем на руках** (с учётом уже лежащего в черновиках): должно отбиваться `AppException`, не уходить в минус. Тест в Task 4.
- **Повторное «Списать» того же факта в тот же черновик**: увеличивает количество порции, не плодит вторую. Тест в Task 3/4.
- **Оформление акта с незаданной причиной хотя бы у одной порции**: `AppException`, эффект не наступает. Тест в Task 5.
- **Частичная до-выдача ниже нормы**: `assertIssuable` отбивает, если `held + выдаётся < норма`. Тест в Task 8.
- **Нематериальная позиция** (нет нормы-количества): held/дефицит не применяются, статус датный, списание её не трогает. Тест в Task 6/7.

---

## Task 1: FulfillmentRecord — количественный item (returnedQuantity-аккумулятор)

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/FulfillmentRecord.php`
- Test: `app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/FulfillmentRecordTest.php` (Create)

**Interfaces:**
- Consumes: `App\Compliance\Domain\ValueObject\Quantity` (`public float $amount`, `public Unit $unit`), `App\Shared\Domain\Aggregate\ValueObject\Percent`.
- Produces: конструктор `__construct(Uuid $id, ProfileCompliance $profileCompliance, string $obligationKey, \DateTimeImmutable $fulfilledAt, ?Quantity $quantity = null, ?Percent $wearPercent = null, ?string $note = null, ?\DateTimeImmutable $manualDueDate = null, float $returnedQuantity = 0.0, ?string $documentId = null)`; методы `heldAmount(): float`, `isDepleted(): bool`, `addReturnedQuantity(float $amount): void`, `returnedQuantity(): float`, `documentId(): ?string`, плюс существующие `getId/obligationKey/fulfilledAt/quantity/wearPercent/note/manualDueDate`. УДАЛЕНЫ: `writeOffActId`/`writeOffReason`/`returnedAt` (поля, параметры, геттеры, `placeInWriteOffAct`/`removeFromWriteOffAct`/`setWriteOffReason`/`isInWriteOffAct`/`markReturned`/`isReturned`/`returnedQuantity(): ?Quantity`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Aggregate\ProfileCompliance\FulfillmentRecord;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class FulfillmentRecordTest extends TestCase
{
    public function test_held_and_depletion_accumulate(): void
    {
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $r = new FulfillmentRecord(Uuid::v4(), $pc, 'req|ботинки', new \DateTimeImmutable('2026-01-10'), new Quantity(2.0, Unit::Pair));

        self::assertSame(2.0, $r->heldAmount());
        self::assertFalse($r->isDepleted());

        $r->addReturnedQuantity(1.0);
        self::assertSame(1.0, $r->heldAmount());
        self::assertFalse($r->isDepleted());
        self::assertSame(1.0, $r->returnedQuantity());

        $r->addReturnedQuantity(1.0);
        self::assertSame(0.0, $r->heldAmount());
        self::assertTrue($r->isDepleted());
    }

    public function test_non_material_fact_has_zero_held_and_not_depleted(): void
    {
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $r = new FulfillmentRecord(Uuid::v4(), $pc, 'req|инструктаж', new \DateTimeImmutable('2026-01-10'));

        self::assertSame(0.0, $r->heldAmount());
        self::assertFalse($r->isDepleted()); // нет количества — не «истощается», живёт по дате
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/FulfillmentRecordTest.php`
Expected: FAIL (методы `heldAmount`/`isDepleted`/`addReturnedQuantity` отсутствуют или сигнатура ctor иная).

- [ ] **Step 3: Rewrite FulfillmentRecord**

Заменить весь файл на:

```php
<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\ValueObject\Quantity;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use Symfony\Component\Uid\Uuid;

/**
 * Факт выдачи — ИСТОЧНИК ИСТИНЫ и количественный item: `quantity` выдано, `returnedQuantity` списано суммарно
 * (аккумулятор), «на руках по факту» = разница. `documentId` — акт выдачи (провенанс «откуда»). Привязан к
 * обязанности по `obligationKey` (requirementId|label). Материальная часть (кол-во/износ) nullable: у процедуры
 * её нет — такой факт в held не участвует и не «истощается». `manualDueDate` — дата для «по документам изготовителя».
 */
class FulfillmentRecord
{
    private readonly Uuid $id;
    private ProfileCompliance $profileCompliance;
    private string $obligationKey;
    private \DateTimeImmutable $fulfilledAt;
    private ?Quantity $quantity;
    private ?float $wearPercent;
    private ?string $note;
    private ?\DateTimeImmutable $manualDueDate;
    private float $returnedQuantity;
    private ?string $documentId;

    public function __construct(
        Uuid $id,
        ProfileCompliance $profileCompliance,
        string $obligationKey,
        \DateTimeImmutable $fulfilledAt,
        ?Quantity $quantity = null,
        ?Percent $wearPercent = null,
        ?string $note = null,
        ?\DateTimeImmutable $manualDueDate = null,
        float $returnedQuantity = 0.0,
        ?string $documentId = null,
    ) {
        $this->id = $id;
        $this->profileCompliance = $profileCompliance;
        $this->obligationKey = $obligationKey;
        $this->fulfilledAt = $fulfilledAt;
        $this->quantity = $quantity;
        $this->wearPercent = null === $wearPercent?->value() ? null : (float) $wearPercent->value();
        $this->note = $note;
        $this->manualDueDate = $manualDueDate;
        $this->returnedQuantity = $returnedQuantity;
        $this->documentId = $documentId;
    }

    /** Списать количество (возврат): накапливается, не превышая выданного. */
    public function addReturnedQuantity(float $amount): void
    {
        $max = $this->quantity?->amount ?? 0.0;
        $this->returnedQuantity = min($max, $this->returnedQuantity + max(0.0, $amount));
    }

    /** На руках по факту = выдано − списано (у нематериального — 0, held к нему неприменим). */
    public function heldAmount(): float
    {
        if (null === $this->quantity) {
            return 0.0;
        }

        return max(0.0, $this->quantity->amount - $this->returnedQuantity);
    }

    /** Материальный факт списан полностью (на руках 0). Нематериальный не истощается. */
    public function isDepleted(): bool
    {
        return null !== $this->quantity && $this->returnedQuantity >= $this->quantity->amount;
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function profileCompliance(): ProfileCompliance
    {
        return $this->profileCompliance;
    }

    public function obligationKey(): string
    {
        return $this->obligationKey;
    }

    public function fulfilledAt(): \DateTimeImmutable
    {
        return $this->fulfilledAt;
    }

    public function quantity(): ?Quantity
    {
        return $this->quantity;
    }

    public function wearPercent(): ?Percent
    {
        return null === $this->wearPercent ? null : new Percent($this->wearPercent);
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function manualDueDate(): ?\DateTimeImmutable
    {
        return $this->manualDueDate;
    }

    public function returnedQuantity(): float
    {
        return $this->returnedQuantity;
    }

    public function documentId(): ?string
    {
        return $this->documentId;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/FulfillmentRecordTest.php`
Expected: PASS. (Агрегат `ProfileCompliance` ещё ссылается на снятые методы — компилятор это не ловит в PHP до вызова; поправим в Task 4-5. Класс-тест изолирован.)

- [ ] **Step 5: Commit**

```bash
git add app/src/Compliance/Domain/Aggregate/ProfileCompliance/FulfillmentRecord.php app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/FulfillmentRecordTest.php
git commit -m "СИЗ: факт выдачи стал количественным item — returnedQuantity-аккумулятор, held/isDepleted, снят whole-move"
```

---

## Task 2: WriteOffItem — порция списания (дочерняя сущность)

**Files:**
- Create: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/WriteOffItem.php`
- Test: `app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/WriteOffItemTest.php` (Create)

**Interfaces:**
- Consumes: `App\Compliance\Domain\Type\WriteOffReason`, `WriteOffAct` (родитель).
- Produces: `__construct(Uuid $id, WriteOffAct $writeOffAct, string $recordId, float $quantity, ?WriteOffReason $reason = null)`; `getId(): string`, `recordId(): string`, `quantity(): float`, `addQuantity(float): void`, `reason(): ?WriteOffReason`, `setReason(?WriteOffReason): void`, `writeOffAct(): WriteOffAct`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffAct;
use App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffItem;
use App\Compliance\Domain\Type\WriteOffReason;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WriteOffItemTest extends TestCase
{
    public function test_portion_holds_record_quantity_and_reason(): void
    {
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $act = new WriteOffAct(Uuid::v4(), $pc, 'req-1', new \DateTimeImmutable('2026-08-01'));
        $recordId = (string) Uuid::v4();

        $item = new WriteOffItem(Uuid::v4(), $act, $recordId, 1.0);
        self::assertSame($recordId, $item->recordId());
        self::assertSame(1.0, $item->quantity());
        self::assertNull($item->reason());

        $item->addQuantity(1.0);
        self::assertSame(2.0, $item->quantity());

        $item->setReason(WriteOffReason::PhysicalWear);
        self::assertSame(WriteOffReason::PhysicalWear, $item->reason());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/WriteOffItemTest.php`
Expected: FAIL («Class WriteOffItem not found»). `WriteOffAct` ещё старый (ctor без изменений — ок).

- [ ] **Step 3: Create WriteOffItem**

```php
<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Type\WriteOffReason;
use Symfony\Component\Uid\Uuid;

/**
 * Порция списания — сколько конкретного факта-выдачи ({@see $recordId}) списано в этом акте ({@see WriteOffAct}) и
 * почему. Один факт может иметь порции в нескольких актах (частями во времени). Наименование/дату тянем из факта.
 */
class WriteOffItem
{
    private readonly Uuid $id;
    private WriteOffAct $writeOffAct;
    private string $recordId;
    private float $quantity;
    private ?WriteOffReason $reason;

    public function __construct(Uuid $id, WriteOffAct $writeOffAct, string $recordId, float $quantity, ?WriteOffReason $reason = null)
    {
        $this->id = $id;
        $this->writeOffAct = $writeOffAct;
        $this->recordId = $recordId;
        $this->quantity = $quantity;
        $this->reason = $reason;
    }

    public function addQuantity(float $amount): void
    {
        $this->quantity += $amount;
    }

    public function setReason(?WriteOffReason $reason): void
    {
        $this->reason = $reason;
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function recordId(): string
    {
        return $this->recordId;
    }

    public function quantity(): float
    {
        return $this->quantity;
    }

    public function reason(): ?WriteOffReason
    {
        return $this->reason;
    }

    public function writeOffAct(): WriteOffAct
    {
        return $this->writeOffAct;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/WriteOffItemTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/src/Compliance/Domain/Aggregate/ProfileCompliance/WriteOffItem.php app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/WriteOffItemTest.php
git commit -m "СИЗ: порция списания WriteOffItem (факт+количество+причина) как дочерняя сущность акта"
```

---

## Task 3: WriteOffAct — коллекция порций

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/WriteOffAct.php`
- Test: `app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/WriteOffActTest.php` (Create)

**Interfaces:**
- Consumes: `WriteOffItem` (Task 2), `WriteOffCommission`, `WriteOffReason`.
- Produces: ctor без изменений `(Uuid $id, ProfileCompliance $profileCompliance, string $requirementId, \DateTimeImmutable $now)`; новые `addPortion(string $recordId, float $quantity, Uuid $portionId, \DateTimeImmutable $now): void` (если порция факта уже есть — увеличивает количество), `removePortion(string $portionId, \DateTimeImmutable $now): void`, `applyReasons(array $reasonByPortionId, \DateTimeImmutable $now): void`, `portionFor(string $recordId): ?WriteOffItem`, `isEmpty(): bool`, `items(): list<WriteOffItem>`; существующие `sign/isDraft/isSigned/assertMutable/getId/requirementId/status/commission/actNumber/actDate/scanFileId/signedAt/profileCompliance/createdAt/updatedAt`; `sign()` дополнительно вызывает `assertReasonsComplete()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffAct;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Compliance\Domain\ValueObject\WriteOffCommission;
use App\Compliance\Domain\ValueObject\WriteOffCommissionMember;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WriteOffActTest extends TestCase
{
    private function act(): WriteOffAct
    {
        return new WriteOffAct(Uuid::v4(), new ProfileCompliance(Uuid::v4(), 'p'), 'req-1', new \DateTimeImmutable('2026-08-01'));
    }

    private function commission(): WriteOffCommission
    {
        return new WriteOffCommission(
            new WriteOffCommissionMember('рук. ОТиПБ', 'Алиханова Н.И.'),
            new WriteOffCommissionMember('спец. ТМЦ', 'Корзун П.Е.'),
        );
    }

    public function test_add_portion_dedups_by_record_and_sums(): void
    {
        $act = $this->act();
        $rec = (string) Uuid::v4();
        $act->addPortion($rec, 1.0, Uuid::v4(), new \DateTimeImmutable('2026-08-01'));
        $act->addPortion($rec, 1.0, Uuid::v4(), new \DateTimeImmutable('2026-08-01')); // тот же факт → +кол-во

        self::assertCount(1, $act->items());
        self::assertSame(2.0, $act->items()[0]->quantity());
    }

    public function test_sign_requires_reason_on_every_portion(): void
    {
        $act = $this->act();
        $act->addPortion((string) Uuid::v4(), 1.0, Uuid::v4(), new \DateTimeImmutable('2026-08-01'));

        $this->expectException(AppException::class);
        $act->sign($this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-1', new \DateTimeImmutable('2026-08-01'));
    }

    public function test_apply_reasons_then_sign_ok(): void
    {
        $act = $this->act();
        $act->addPortion((string) Uuid::v4(), 1.0, $pid = Uuid::v4(), new \DateTimeImmutable('2026-08-01'));
        $act->applyReasons([(string) $pid => WriteOffReason::PhysicalWear], new \DateTimeImmutable('2026-08-01'));

        $act->sign($this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-1', new \DateTimeImmutable('2026-08-01'));
        self::assertTrue($act->isSigned());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/WriteOffActTest.php`
Expected: FAIL (`addPortion`/`applyReasons` отсутствуют).

- [ ] **Step 3: Rewrite WriteOffAct**

Заменить весь файл на:

```php
<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Type\WriteOffReason;
use App\Compliance\Domain\ValueObject\WriteOffCommission;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Uuid;

/**
 * Акт списания СИЗ (на человека, по требованию) — заголовок-документ, зеркало акта получения. Состав —
 * порции {@see WriteOffItem} (сколько какого факта списано + причина). Цикл {@see DocumentStatus}: Черновик
 * (кладём/убираем порции, задаём причины) → Подписан (комиссия + №/дата + скан, заморожен). Эффект (гашение
 * количества на фактах + пересчёт) наступает при подписи — в {@see ProfileCompliance::signWriteOffAct()}.
 */
class WriteOffAct
{
    private readonly Uuid $id;
    private ProfileCompliance $profileCompliance;
    private string $requirementId;
    /** @var Collection<int, WriteOffItem> */
    private Collection $items;
    private DocumentStatus $status;
    private ?WriteOffCommission $commission = null;
    private ?string $actNumber = null;
    private ?\DateTimeImmutable $actDate = null;
    private ?string $scanFileId = null;
    private ?\DateTimeImmutable $signedAt = null;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $id, ProfileCompliance $profileCompliance, string $requirementId, \DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->profileCompliance = $profileCompliance;
        $this->requirementId = $requirementId;
        $this->items = new ArrayCollection();
        $this->status = DocumentStatus::Formed;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** Положить порцию (черновик): если порция этого факта уже есть — увеличиваем количество, иначе добавляем. */
    public function addPortion(string $recordId, float $quantity, Uuid $portionId, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        $existing = $this->portionFor($recordId);
        if (null !== $existing) {
            $existing->addQuantity($quantity);
        } else {
            $this->items->add(new WriteOffItem($portionId, $this, $recordId, $quantity));
        }
        $this->updatedAt = $now;
    }

    public function removePortion(string $portionId, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        foreach ($this->items as $item) {
            if ($item->getId() === $portionId) {
                $this->items->removeElement($item);
                $this->updatedAt = $now;

                return;
            }
        }
    }

    /** @param array<string, WriteOffReason> $reasonByPortionId */
    public function applyReasons(array $reasonByPortionId, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        foreach ($this->items as $item) {
            if (isset($reasonByPortionId[$item->getId()])) {
                $item->setReason($reasonByPortionId[$item->getId()]);
            }
        }
        $this->updatedAt = $now;
    }

    public function portionFor(string $recordId): ?WriteOffItem
    {
        foreach ($this->items as $item) {
            if ($item->recordId() === $recordId) {
                return $item;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /** @return list<WriteOffItem> */
    public function items(): array
    {
        return array_values($this->items->toArray());
    }

    public function sign(WriteOffCommission $commission, string $actNumber, \DateTimeImmutable $actDate, string $scanFileId, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        $this->assertReasonsComplete();
        if ('' === trim($scanFileId)) {
            throw new AppException('Приложите скан подписанного акта списания.');
        }
        $this->commission = $commission;
        $this->actNumber = trim($actNumber);
        $this->actDate = $actDate;
        $this->scanFileId = $scanFileId;
        $this->status = DocumentStatus::Signed;
        $this->signedAt = $now;
        $this->updatedAt = $now;
    }

    public function isDraft(): bool
    {
        return DocumentStatus::Formed === $this->status;
    }

    public function isSigned(): bool
    {
        return DocumentStatus::Signed === $this->status;
    }

    public function assertMutable(): void
    {
        if (!$this->isDraft()) {
            throw new AppException('Акт списания подписан — его нельзя изменить или удалить.');
        }
    }

    private function assertReasonsComplete(): void
    {
        foreach ($this->items as $item) {
            if (null === $item->reason()) {
                throw new AppException('Укажите причину списания для всех позиций акта.');
            }
        }
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function requirementId(): string
    {
        return $this->requirementId;
    }

    public function status(): DocumentStatus
    {
        return $this->status;
    }

    public function commission(): ?WriteOffCommission
    {
        return $this->commission;
    }

    public function actNumber(): ?string
    {
        return $this->actNumber;
    }

    public function actDate(): ?\DateTimeImmutable
    {
        return $this->actDate;
    }

    public function scanFileId(): ?string
    {
        return $this->scanFileId;
    }

    public function signedAt(): ?\DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function profileCompliance(): ProfileCompliance
    {
        return $this->profileCompliance;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/WriteOffActTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/src/Compliance/Domain/Aggregate/ProfileCompliance/WriteOffAct.php app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/WriteOffActTest.php
git commit -m "СИЗ: акт списания держит порции (one-to-many), дедуп по факту, подпись требует причин"
```

---

## Task 4: ProfileCompliance — списание порциями + откат + причины + held-хелперы

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php`
- Test: `app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php` (Modify — заменить write-off тесты)

**Interfaces:**
- Consumes: `WriteOffAct`/`WriteOffItem` (Tasks 2-3), `FulfillmentRecord` (Task 1), `WriteOffReason`, `ObligationDueCalculator`, `WriteOffCommission`.
- Produces (публичное):
  - `openWriteOffDraftFor(string $requirementId): ?WriteOffAct`
  - `heldOf(string $obligationKey): float` — Σ `heldAmount()` по фактам ключа
  - `availableToWriteOff(string $recordId): float` — `held факта` минус уже лежащее в черновиках-порциях этого факта
  - `writeOff(Uuid $candidateActId, string $requirementId, array $portions, \DateTimeImmutable $now): void` где `$portions` = `list<array{recordId: string, quantity: float}>`; для каждой: `available >= quantity > 0`, иначе `AppException`; `addPortion(recordId, quantity, Uuid::v7(), now)` в открытый/новый черновик; пустой новый акт не держим
  - `cancelWriteOffItem(string $actId, string $portionId, \DateTimeImmutable $now): void` — убрать порцию; пустой акт удалить
  - `applyWriteOffReasons(string $actId, array $reasonByPortionId, \DateTimeImmutable $now): void`
  - `itemsOfWriteOffAct(string $actId): list<WriteOffItem>`
  - `recordById(string $recordId): ?FulfillmentRecord` (private)
- Инвариант: списывать можно только из действующей карточки (`signedDocumentsFor($requirementId)` непусто).

- [ ] **Step 1: Write the failing test** (заменяет прежние write-off тесты в `ProfileComplianceTest`; остальные тесты файла не трогаем)

Удалить методы `test_write_off_*`/`test_sign_write_off_*`/`test_cancel_write_off_*`/`test_second_write_off_*`/`signedGlovesCard`/`commission` (если есть от прошлой итерации) и добавить:

```php
    private function signedGlovesCard(ProfileCompliance $pc, \DateTimeImmutable $at, float $amount = 10.0): void
    {
        $docId = Uuid::v4();
        $pc->formDraft($docId, $this->reqId, $at);
        $pc->signDraft((string) $docId, 'scan-1', [$this->glovesLine($amount, $at->format('Y-m-d'))], $this->calc, $at);
    }

    private function commission(): WriteOffCommission
    {
        return new WriteOffCommission(
            new WriteOffCommissionMember('рук. ОТиПБ', 'Алиханова Н.И.'),
            new WriteOffCommissionMember('спец. ТМЦ', 'Корзун П.Е.'),
        );
    }

    public function test_write_off_portion_without_effect(): void
    {
        $pc = $this->pcWithGloves();
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'), 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $writeOffId = Uuid::v4();

        $pc->writeOff($writeOffId, $this->reqId, [['recordId' => $recordId, 'quantity' => 1.0]], $this->now);

        // эффекта нет — на руках всё ещё 2, нового черновика нет
        self::assertSame(2.0, $pc->heldOf($this->glovesKey()));
        self::assertNull($pc->openDraftFor($this->reqId));
        self::assertCount(1, $pc->getWriteOffActs());
        self::assertCount(1, $pc->itemsOfWriteOffAct((string) $writeOffId));
        self::assertSame(1.0, $pc->itemsOfWriteOffAct((string) $writeOffId)[0]->quantity());
        // доступно к списанию уменьшилось на лежащее в черновике
        self::assertSame(1.0, $pc->availableToWriteOff($recordId));
    }

    public function test_write_off_more_than_available_throws(): void
    {
        $pc = $this->pcWithGloves();
        $this->signedGlovesCard($pc, $this->now, 2.0);
        $recordId = $pc->getRecords()[0]->getId();

        $this->expectException(AppException::class);
        $pc->writeOff(Uuid::v4(), $this->reqId, [['recordId' => $recordId, 'quantity' => 3.0]], $this->now);
    }

    public function test_repeated_write_off_same_fact_increases_portion(): void
    {
        $pc = $this->pcWithGloves();
        $this->signedGlovesCard($pc, $this->now, 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $writeOffId = Uuid::v4();

        $pc->writeOff($writeOffId, $this->reqId, [['recordId' => $recordId, 'quantity' => 1.0]], $this->now);
        $pc->writeOff(Uuid::v4(), $this->reqId, [['recordId' => $recordId, 'quantity' => 1.0]], $this->now); // тот же факт, тот же черновик

        self::assertCount(1, $pc->getWriteOffActs());
        self::assertCount(1, $pc->itemsOfWriteOffAct((string) $writeOffId));
        self::assertSame(2.0, $pc->itemsOfWriteOffAct((string) $writeOffId)[0]->quantity());
    }

    public function test_cancel_portion_restores_and_drops_empty_act(): void
    {
        $pc = $this->pcWithGloves();
        $this->signedGlovesCard($pc, $this->now, 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $writeOffId = Uuid::v4();
        $pc->writeOff($writeOffId, $this->reqId, [['recordId' => $recordId, 'quantity' => 1.0]], $this->now);
        $portionId = $pc->itemsOfWriteOffAct((string) $writeOffId)[0]->getId();

        $pc->cancelWriteOffItem((string) $writeOffId, $portionId, $this->now);

        self::assertCount(0, $pc->getWriteOffActs());
        self::assertSame(2.0, $pc->availableToWriteOff($recordId));
    }

    public function test_write_off_requires_active_card(): void
    {
        $pc = $this->pcWithGloves();
        $pc->formDraft(Uuid::v4(), $this->reqId, $this->now); // только черновик выдачи

        $this->expectException(AppException::class);
        $pc->writeOff(Uuid::v4(), $this->reqId, [['recordId' => (string) Uuid::v4(), 'quantity' => 1.0]], $this->now);
    }
```

Убедиться, что в шапке файла есть `use` для `WriteOffCommission`, `WriteOffCommissionMember` (из `App\Compliance\Domain\ValueObject\`), `WriteOffReason`, `AppException`, `Uuid` — добавить отсутствующие. `pcWithGloves()` даёт материальную позицию «Перчатки» норма 10 (для held-тестов норму не важна); `glovesLine($amount, $date)` и `glovesKey()` уже есть в файле.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php --filter test_write_off`
Expected: FAIL (методы `heldOf`/`availableToWriteOff`/`writeOff(portions)`/`cancelWriteOffItem`/`itemsOfWriteOffAct` отсутствуют или сигнатуры иные).

- [ ] **Step 3: Rewrite the write-off cluster in ProfileCompliance**

Заменить блок методов `openWriteOffDraftFor` … `signWriteOffAct` (и `currentFactOfKey`/`recordById`/`writeOffActById`, если задевает) на (эффект-подпись `signWriteOffAct` — в Task 5; здесь объявить заглушку, которую Task 5 наполнит, чтобы файл компилировался — НО лучше реализовать сразу, см. Task 5; в этой задаче ставим всё, кроме тела эффекта, которое Task 5 добавит). Реализация:

```php
    /** Открытый черновик акта списания по требованию (корзина; инвариант: не более одного). */
    public function openWriteOffDraftFor(string $requirementId): ?WriteOffAct
    {
        foreach ($this->writeOffActs as $act) {
            if ($act->requirementId() === $requirementId && $act->isDraft()) {
                return $act;
            }
        }

        return null;
    }

    /** На руках по позиции = Σ heldAmount по её фактам. */
    public function heldOf(string $obligationKey): float
    {
        $sum = 0.0;
        foreach ($this->records as $record) {
            if ($record->obligationKey() === $obligationKey) {
                $sum += $record->heldAmount();
            }
        }

        return $sum;
    }

    /** Доступно к списанию по факту = на руках по факту минус уже лежащее в черновиках-порциях этого факта. */
    public function availableToWriteOff(string $recordId): float
    {
        $fact = $this->recordById($recordId);
        if (null === $fact) {
            return 0.0;
        }
        $inDrafts = 0.0;
        foreach ($this->writeOffActs as $act) {
            if (!$act->isDraft()) {
                continue;
            }
            $portion = $act->portionFor($recordId);
            if (null !== $portion) {
                $inDrafts += $portion->quantity();
            }
        }

        return max(0.0, $fact->heldAmount() - $inDrafts);
    }

    /**
     * Положить порции в корзину (черновик акта списания). Эффекта нет — он на оформлении акта.
     * Списывать можно только из действующей карточки.
     *
     * @param list<array{recordId: string, quantity: float}> $portions
     */
    public function writeOff(Uuid $candidateActId, string $requirementId, array $portions, \DateTimeImmutable $now): void
    {
        if ([] === $this->signedDocumentsFor($requirementId)) {
            throw new AppException('Списать можно только из действующей карточки — черновик не списывается.');
        }
        $act = $this->openWriteOffDraftFor($requirementId);
        $isNew = null === $act;
        if (null === $act) {
            $act = new WriteOffAct($candidateActId, $this, $requirementId, $now);
            $this->writeOffActs->add($act);
        }
        $added = 0;
        foreach ($portions as $portion) {
            $recordId = (string) $portion['recordId'];
            $quantity = (float) $portion['quantity'];
            if ($quantity <= 0.0) {
                continue;
            }
            if ($quantity > $this->availableToWriteOff($recordId)) {
                throw new AppException('Нельзя списать больше, чем на руках.');
            }
            $act->addPortion($recordId, $quantity, Uuid::v7(), $now);
            ++$added;
        }
        if (0 === $added && $isNew) {
            $this->writeOffActs->removeElement($act);
        }
    }

    public function cancelWriteOffItem(string $actId, string $portionId, \DateTimeImmutable $now): void
    {
        $act = $this->writeOffActById($actId) ?? throw new AppException('Акт списания не найден.');
        $act->removePortion($portionId, $now);
        if ($act->isEmpty()) {
            $this->writeOffActs->removeElement($act);
        }
    }

    /** @param array<string, WriteOffReason> $reasonByPortionId */
    public function applyWriteOffReasons(string $actId, array $reasonByPortionId, \DateTimeImmutable $now): void
    {
        $act = $this->writeOffActById($actId) ?? throw new AppException('Акт списания не найден.');
        $act->applyReasons($reasonByPortionId, $now);
    }

    /** @return list<WriteOffItem> */
    public function itemsOfWriteOffAct(string $actId): array
    {
        $act = $this->writeOffActById($actId);

        return null === $act ? [] : $act->items();
    }

    /** Оформить акт списания (комиссия+№/дата+скан): замораживает и гасит количество на фактах + пересчёт. Тело эффекта — Task 5. */
    public function signWriteOffAct(string $actId, WriteOffCommission $commission, string $actNumber, \DateTimeImmutable $actDate, string $scanFileId, \DateTimeImmutable $now, ObligationDueCalculator $calculator): void
    {
        $act = $this->writeOffActById($actId) ?? throw new AppException('Акт списания не найден.');
        $act->sign($commission, $actNumber, $actDate, $scanFileId, $now);
        foreach ($act->items() as $portion) {
            $fact = $this->recordById($portion->recordId());
            if (null === $fact) {
                continue;
            }
            $fact->addReturnedQuantity($portion->quantity());
            $this->recomputeObligation($fact->obligationKey(), $calculator);
        }
    }

    private function recordById(string $recordId): ?FulfillmentRecord
    {
        foreach ($this->records as $record) {
            if ($record->getId() === $recordId) {
                return $record;
            }
        }

        return null;
    }

    private function writeOffActById(string $actId): ?WriteOffAct
    {
        foreach ($this->writeOffActs as $act) {
            if ($act->getId() === $actId) {
                return $act;
            }
        }

        return null;
    }
```

Также: удалить прежние `currentFactOfKey`, `amountStr`, `obligationLabelOf` если не используются (grep!), либо сохранить `obligationLabelOf` (используется проектором/контроллером — оставить). Удалить любые ссылки на снятые методы факта (`markReturned`/`isReturned`/`placeInWriteOffAct`) — заменить логикой выше. В `recomputeObligation` «списанность» факта теперь = `isDepleted()` (см. Task 6).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php --filter test_write_off`
Expected: PASS (кроме тестов, зависящих от recompute/held — они позеленеют после Task 6; если в этой задаче `recomputeObligation` ещё использует старый `isReturned`, временно заменить на `isDepleted()` сразу — см. Task 6 Step 3, можно внести здесь).

- [ ] **Step 5: Commit**

```bash
git add app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php
git commit -m "СИЗ: списание порциями — writeOff(портфель), откат, причины, held/available по факту (инвариант действующей карточки)"
```

---

## Task 5: ProfileCompliance.signWriteOffAct — эффект (гашение количества + пересчёт)

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php` (тело `signWriteOffAct` + `recomputeObligation` на `isDepleted`)
- Test: `app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php` (Modify)

**Interfaces:**
- Consumes: Task 4 методы; `ObligationDueCalculator`.
- Produces: поведение «1 из 2 → оформил → held 1; второй → оформил → held 0».

> Если в Task 4 тело `signWriteOffAct` и `recomputeObligation(isDepleted)` уже реализованы полностью — эта задача сводится к тестам эффекта. Иначе реализовать тело здесь.

- [ ] **Step 1: Write the failing test**

```php
    public function test_sign_act_applies_partial_write_off(): void
    {
        $pc = $this->pcWithGloves(2.0); // норма 2 (см. Step 3 — pcWithGloves принимает норму)
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'), 2.0);
        $recordId = $pc->getRecords()[0]->getId();
        $writeOffId = Uuid::v4();
        $pc->writeOff($writeOffId, $this->reqId, [['recordId' => $recordId, 'quantity' => 1.0]], $this->now);
        $portionId = $pc->itemsOfWriteOffAct((string) $writeOffId)[0]->getId();
        $pc->applyWriteOffReasons((string) $writeOffId, [$portionId => WriteOffReason::PhysicalWear], $this->now);

        $pc->signWriteOffAct((string) $writeOffId, $this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan-wo', $this->now, $this->calc);

        self::assertTrue($pc->getWriteOffActs()[0]->isSigned());
        self::assertSame(1.0, $pc->heldOf($this->glovesKey())); // на руках 1 из 2
        self::assertNotNull($pc->getObligations()[0]->lastFulfilledAt());

        // списываем второй
        $w2 = Uuid::v4();
        $pc->writeOff($w2, $this->reqId, [['recordId' => $recordId, 'quantity' => 1.0]], $this->now);
        $p2 = $pc->itemsOfWriteOffAct((string) $w2)[0]->getId();
        $pc->applyWriteOffReasons((string) $w2, [$p2 => WriteOffReason::PhysicalWear], $this->now);
        $pc->signWriteOffAct((string) $w2, $this->commission(), '40', new \DateTimeImmutable('2026-09-01'), 'scan-wo2', $this->now, $this->calc);

        self::assertSame(0.0, $pc->heldOf($this->glovesKey())); // всё списано
        self::assertNull($pc->getObligations()[0]->lastFulfilledAt()); // позиция освобождена
    }
```

Обновить `pcWithGloves()` → принять норму: `private function pcWithGloves(float $norm = 10.0): ProfileCompliance` и использовать `new Quantity($norm, Unit::Pair)` в `putObligation`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php --filter test_sign_act_applies_partial_write_off`
Expected: FAIL (held не гасится / lastFulfilledAt не обнуляется).

- [ ] **Step 3: Ensure effect + recompute use isDepleted**

В `recomputeObligation` заменить условие исключения факта на `isDepleted()` и агрегировать held для проекции (held — Task 6). «Текущий факт» для `lastFulfilledAt` = последний факт ключа с `heldAmount() > 0`:

```php
    private function recomputeObligation(string $key, ObligationDueCalculator $calculator): void
    {
        $obligation = null;
        foreach ($this->obligations as $candidate) {
            if ($candidate->key() === $key) {
                $obligation = $candidate;
                break;
            }
        }
        if (null === $obligation) {
            return;
        }

        $latest = null;
        $held = 0.0;
        foreach ($this->records as $record) {
            if ($record->obligationKey() !== $key) {
                continue;
            }
            $held += $record->heldAmount();
            $current = $record->isDepleted() ? false : true; // материальный истощён → не текущий; нематериальный всегда текущий
            if ($current && (null === $latest || $record->fulfilledAt() > $latest->fulfilledAt())) {
                $latest = $record;
            }
        }

        $lastFulfilledAt = $latest?->fulfilledAt();
        $nextDueAt = $calculator->nextDue($obligation->cadence(), $lastFulfilledAt, $latest?->manualDueDate());
        $obligation->setDates($lastFulfilledAt, $nextDueAt);
        $obligation->setHeldQuantity($held); // метод Task 6
    }
```

(Если Task 6 ещё не добавил `setHeldQuantity` — эта строка не скомпилируется; задачи 5 и 6 выполняются вместе либо 6 раньше. При SDD-исполнении допустимо слить 5+6; см. примечание исполнителю ниже.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php`
Expected: PASS (все доменные тесты агрегата).

- [ ] **Step 5: Commit**

```bash
git add app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php
git commit -m "СИЗ: оформление акта списания гасит количество на фактах и пересчитывает позицию (частичное)"
```

> Примечание исполнителю: Tasks 5 и 6 связаны через `setHeldQuantity`/`recomputeObligation`. Допустимо выполнить их как один коммит-блок. Порядок: сначала добавить `heldQuantity` в `TrackedObligation` (Task 6 Step 3), затем эффект (Task 5).

---

## Task 6: TrackedObligation.heldQuantity + денормализация held

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligation.php`
- Test: `app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php` (Modify — добавить ассерт held в проекции)

**Interfaces:**
- Produces: `heldQuantity(): float`, `setHeldQuantity(float): void`; существующие `quantity(): ?Quantity` (норма), `type()`, `cadence()`, `key()`, `lastFulfilledAt()`, `nextDueAt()`, `isActive()`, `setDates()`, `setActive()`.

- [ ] **Step 1: Write the failing test** (добавить в `ProfileComplianceTest`)

```php
    public function test_projection_holds_quantity(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'), 2.0);

        self::assertSame(2.0, $pc->getObligations()[0]->heldQuantity());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php --filter test_projection_holds_quantity`
Expected: FAIL (`heldQuantity` отсутствует).

- [ ] **Step 3: Add heldQuantity to TrackedObligation**

Добавить поле и методы (рядом с `lastFulfilledAt`/`nextDueAt`):

```php
    private float $heldQuantity = 0.0;

    public function heldQuantity(): float
    {
        return $this->heldQuantity;
    }

    public function setHeldQuantity(float $heldQuantity): void
    {
        $this->heldQuantity = $heldQuantity;
    }
```

Инициализировать `0.0` в конструкторе (если конструктор присваивает явно — добавить `$this->heldQuantity = 0.0;`). `recomputeObligation` (Task 5) зовёт `setHeldQuantity`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/src/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligation.php app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php
git commit -m "СИЗ: проекция обязанности несёт heldQuantity (денормализация на руках для дашборда/статуса)"
```

---

## Task 7: ComplianceStatusResolver — held vs норма

**Files:**
- Modify: `app/src/Compliance/Domain/Service/ComplianceStatusResolver.php`
- Test: `app/tests/Unit/Compliance/Domain/Service/ComplianceStatusResolverTest.php` (Modify/Create)

**Interfaces:**
- Consumes: `ComplianceStatus` (Green/Yellow/Red).
- Produces: расширенная сигнатура `statusFor(bool $active, ?\DateTimeImmutable $lastFulfilledAt, ?\DateTimeImmutable $nextDueAt, \DateTimeImmutable $now, ?float $norm = null, float $held = 0.0): ComplianceStatus`. Правило: если `null !== $norm && $held < $norm` → `Red` (недовыдано); иначе прежняя датная логика. Старые вызовы (`worstStatus` в агрегате) обновить на передачу norm/held из `TrackedObligation`.

- [ ] **Step 1: Write the failing test**

```php
    public function test_under_issued_is_red_even_if_in_date(): void
    {
        $resolver = new ComplianceStatusResolver();
        $now = new \DateTimeImmutable('2026-06-01');
        // активно, выдано вчера, срок далеко, НО на руках 1 из нормы 2
        $status = $resolver->statusFor(true, new \DateTimeImmutable('2026-05-31'), new \DateTimeImmutable('2027-05-31'), $now, 2.0, 1.0);
        self::assertSame(ComplianceStatus::Red, $status);
    }

    public function test_held_meets_norm_in_date_is_green(): void
    {
        $resolver = new ComplianceStatusResolver();
        $now = new \DateTimeImmutable('2026-06-01');
        $status = $resolver->statusFor(true, new \DateTimeImmutable('2026-05-31'), new \DateTimeImmutable('2027-05-31'), $now, 2.0, 2.0);
        self::assertSame(ComplianceStatus::Green, $status);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Service/ComplianceStatusResolverTest.php`
Expected: FAIL (сигнатура `statusFor` без norm/held).

- [ ] **Step 3: Extend resolver**

Добавить параметры и ветку дефицита в начало `statusFor` (точную датную часть сохранить):

```php
    public function statusFor(
        bool $active,
        ?\DateTimeImmutable $lastFulfilledAt,
        ?\DateTimeImmutable $nextDueAt,
        \DateTimeImmutable $now,
        ?float $norm = null,
        float $held = 0.0,
    ): ComplianceStatus {
        if (!$active) {
            return ComplianceStatus::Red;
        }
        if (null !== $norm && $held < $norm) {
            return ComplianceStatus::Red; // недовыдано по количеству
        }
        // ... существующая датная логика (lastFulfilledAt/nextDueAt/DUE_SOON_DAYS) без изменений ...
    }
```

Обновить `ProfileCompliance::worstStatus()`: при вызове резолвера передавать `$obligation->quantity()?->amount` как `$norm` и `$obligation->heldQuantity()` как `$held` (норма берётся из `TrackedObligation::quantity()` — это VO нормы; для нематериальных `quantity()` = null → дефицит не проверяется).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Service/ComplianceStatusResolverTest.php && cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/src/Compliance/Domain/Service/ComplianceStatusResolver.php app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php app/tests/Unit/Compliance/Domain/Service/ComplianceStatusResolverTest.php
git commit -m "СИЗ: статус краснеет при недовыдаче (на руках меньше нормы), иначе по сроку"
```

---

## Task 8: assertIssuable — held + выдаётся ≥ норма

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php` (`assertIssuable`)
- Test: `app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php`

**Interfaces:**
- Consumes: `heldOf()` (Task 4), `IssuanceLine` (`obligationKey`, `quantity: ?Quantity`).
- Produces: `assertIssuable(array $lines): void` теперь проверяет `heldOf(key) + line.quantity.amount >= norm.amount`.

- [ ] **Step 1: Write the failing test**

```php
    public function test_issue_below_norm_minus_held_throws(): void
    {
        $pc = $this->pcWithGloves(2.0);
        // ничего на руках (held 0), норма 2 → выдать 1 нельзя
        $this->expectException(AppException::class);
        $pc->assertIssuable([$this->glovesLine(1.0)]);
    }

    public function test_issue_covers_deficit_ok(): void
    {
        $pc = $this->pcWithGloves(2.0);
        $this->signedGlovesCard($pc, new \DateTimeImmutable('2026-06-01'), 2.0); // held 2
        $recordId = $pc->getRecords()[0]->getId();
        $w = Uuid::v4();
        $pc->writeOff($w, $this->reqId, [['recordId' => $recordId, 'quantity' => 1.0]], $this->now);
        $pid = $pc->itemsOfWriteOffAct((string) $w)[0]->getId();
        $pc->applyWriteOffReasons((string) $w, [$pid => WriteOffReason::PhysicalWear], $this->now);
        $pc->signWriteOffAct((string) $w, $this->commission(), '39', new \DateTimeImmutable('2026-08-01'), 'scan', $this->now, $this->calc);
        // held 1, норма 2 → до-выдать 1 достаточно
        $pc->assertIssuable([$this->glovesLine(1.0)]); // не бросает
        self::assertSame(1.0, $pc->heldOf($this->glovesKey()));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php --filter test_issue`
Expected: FAIL (старый assertIssuable проверяет только `line.quantity >= norm`).

- [ ] **Step 3: Rewrite assertIssuable**

```php
    /**
     * Инвариант выдачи: по материальным позициям «на руках + выдаётся» не меньше нормы (нельзя оставить ниже нормы).
     * Без мутаций — можно звать до промоута скана.
     *
     * @param IssuanceLine[] $lines
     */
    public function assertIssuable(array $lines): void
    {
        foreach ($lines as $line) {
            $obligation = $this->obligationByKey($line->obligationKey);
            if (null === $obligation) {
                continue;
            }
            $norm = $obligation->quantity();
            if (ComplianceType::Material !== $obligation->type() || null === $norm) {
                continue;
            }
            $issued = $line->quantity?->amount ?? 0.0;
            if ($this->heldOf($line->obligationKey) + $issued < $norm->amount) {
                throw new AppException(sprintf('По позиции «%s» на руках с учётом выдачи меньше нормы (%s).', $obligation->label(), $norm->label()));
            }
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php
git commit -m "СИЗ: выдача не может оставить позицию ниже нормы (на руках + выдаётся ≥ норма)"
```

---

## Task 9: ORM-маппинги + миграция (схема)

**Files:**
- Create: `app/src/Compliance/Infrastructure/Database/ORM/Aggregate/ProfileCompliance.WriteOffItem.orm.xml`
- Modify: `app/src/Compliance/Infrastructure/Database/ORM/Aggregate/ProfileCompliance.WriteOffAct.orm.xml` (one-to-many items)
- Modify: `app/src/Compliance/Infrastructure/Database/ORM/Aggregate/ProfileCompliance.FulfillmentRecord.orm.xml` (returned_quantity → float, снять write_off_*/returned_at)
- Modify: `app/src/Compliance/Infrastructure/Database/ORM/Aggregate/ProfileCompliance.TrackedObligation.orm.xml` (+held_quantity)
- Modify: `app/src/Shared/Infrastructure/Database/Migrations/Version20261002120000.php` (реформировать Д5-миграцию под порции)
- Modify: `app/src/Shared/Infrastructure/Database/Migrations/Version20260930120000.php` (базовая: returned_quantity как double, без write_off_*/returned_at — т.к. прод пуст и схему реформируем; held_quantity на tracked_obligation)

**Interfaces:**
- Produces: таблица `compliance_write_off_item` (id uuid PK, write_off_act_id uuid FK→compliance_write_off_act ON DELETE CASCADE, record_id varchar(36), quantity double precision, reason varchar(32) nullable); `compliance_fulfillment_record` без `write_off_act_id`/`write_off_reason`/`returned_at`, `returned_quantity DOUBLE PRECISION NOT NULL DEFAULT 0`, есть `document_id varchar(36)`; `compliance_tracked_obligation.held_quantity DOUBLE PRECISION NOT NULL DEFAULT 0`.

- [ ] **Step 1: WriteOffItem ORM**

`ProfileCompliance.WriteOffItem.orm.xml`:

```xml
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="https://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="https://doctrine-project.org/schemas/orm/doctrine-mapping
                          https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">
    <entity name="App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffItem" table="compliance_write_off_item">
        <id name="id" type="uuid" column="id">
            <generator strategy="NONE"/>
        </id>
        <field name="recordId" type="string" length="36" column="record_id"/>
        <field name="quantity" type="float" column="quantity"/>
        <field name="reason" type="string" length="32" column="reason" nullable="true"
               enum-type="App\Compliance\Domain\Type\WriteOffReason"/>
        <many-to-one field="writeOffAct"
                     target-entity="App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffAct"
                     inversed-by="items" fetch="LAZY">
            <join-column name="write_off_act_id" nullable="false" on-delete="CASCADE"/>
        </many-to-one>
    </entity>
</doctrine-mapping>
```

- [ ] **Step 2: WriteOffAct ORM — one-to-many items**

В `ProfileCompliance.WriteOffAct.orm.xml` добавить перед закрытием `</entity>`:

```xml
        <one-to-many field="items"
                     target-entity="App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffItem"
                     mapped-by="writeOffAct" orphan-removal="true">
            <cascade>
                <cascade-persist/>
                <cascade-remove/>
            </cascade>
        </one-to-many>
```

- [ ] **Step 3: FulfillmentRecord ORM**

В `ProfileCompliance.FulfillmentRecord.orm.xml`: удалить строки полей `writeOffActId`/`writeOffReason`/`returnedAt`; заменить `returnedQuantity`:

```xml
        <field name="returnedQuantity" type="float" column="returned_quantity"/>
        <field name="documentId" type="string" length="36" column="document_id" nullable="true"/>
```

(оставить `quantity`/`wearPercent`/`note`/`manualDueDate` как есть).

- [ ] **Step 4: TrackedObligation ORM**

В `ProfileCompliance.TrackedObligation.orm.xml` добавить поле:

```xml
        <field name="heldQuantity" type="float" column="held_quantity"/>
```

- [ ] **Step 5: Миграции**

В `Version20260930120000.php` (базовая создающая таблицы) — привести CREATE к целевой схеме: в `compliance_fulfillment_record` убрать `returned_at`, заменить `returned_quantity JSONB` на `returned_quantity DOUBLE PRECISION NOT NULL DEFAULT 0`; в `compliance_tracked_obligation` добавить `held_quantity DOUBLE PRECISION NOT NULL DEFAULT 0`.

В `Version20261002120000.php` заменить `up()` целиком:

```php
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_fulfillment_record ADD COLUMN IF NOT EXISTS document_id VARCHAR(36) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS compliance_write_off_act (
                id UUID NOT NULL,
                profile_compliance_id UUID NOT NULL,
                requirement_id VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                commission JSONB DEFAULT NULL,
                act_number VARCHAR(64) DEFAULT NULL,
                act_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                scan_file_id VARCHAR(64) DEFAULT NULL,
                signed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_write_off_act_profile FOREIGN KEY (profile_compliance_id)
                    REFERENCES compliance_profile_compliance (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_write_off_act_requirement ON compliance_write_off_act (requirement_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS compliance_write_off_item (
                id UUID NOT NULL,
                write_off_act_id UUID NOT NULL,
                record_id VARCHAR(36) NOT NULL,
                quantity DOUBLE PRECISION NOT NULL,
                reason VARCHAR(32) DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_write_off_item_act FOREIGN KEY (write_off_act_id)
                    REFERENCES compliance_write_off_act (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_write_off_item_act ON compliance_write_off_item (write_off_act_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_write_off_item_record ON compliance_write_off_item (record_id)');
    }
```

и `down()` — DROP `compliance_write_off_item`, `compliance_write_off_act`, и `ALTER ... DROP COLUMN IF EXISTS document_id`.

- [ ] **Step 6: Пересоздать тест-БД и проверить схему**

Run:
```bash
docker compose -f docker-compose.test.yml rm -sfv test_db
docker compose -f docker-compose.test.yml run --rm test_php-cli bin/console doctrine:migrations:migrate -n
```
Expected: «Successfully migrated» без ошибок; 84+ миграций.

- [ ] **Step 7: Commit**

```bash
git add app/src/Compliance/Infrastructure/Database/ORM/Aggregate/ app/src/Shared/Infrastructure/Database/Migrations/Version20260930120000.php app/src/Shared/Infrastructure/Database/Migrations/Version20261002120000.php
git commit -m "СИЗ: схема под порции списания — таблица write_off_item, returned_quantity числом, held_quantity на проекции"
```

---

## Task 10: ComplianceBucketResolver + дашборд-SQL — held vs норма

**Files:**
- Modify: `app/src/Compliance/Application/ReadModel/ComplianceBucketResolver.php`
- Modify: `app/src/Compliance/Infrastructure/Repository/ProfileComplianceRepository.php` (SQL-бакеты дашборда, если считаются в БД)
- Test: `app/tests/Unit/Compliance/Application/ReadModel/ComplianceBucketResolverTest.php` (Modify/Create) + функц. дашборда при наличии

**Interfaces:**
- Consumes: `TrackedObligation` (`quantity()`, `heldQuantity()`, даты, active).
- Produces: бакет `Overdue/Missing` при `held < норма` (дефицит трактуем как Missing/Overdue — согласовать с существующими бакетами: дефицit > 0 → `Missing`).

- [ ] **Step 1: Write the failing test**

```php
    public function test_deficit_bucket_is_missing(): void
    {
        $resolver = new ComplianceBucketResolver();
        // active, в срок, но на руках 1 из 2 → дефицит → Missing
        $bucket = $resolver->bucketFor(true, new \DateTimeImmutable('2026-05-31'), new \DateTimeImmutable('2027-05-31'), new \DateTimeImmutable('2026-06-01'), 2.0, 1.0);
        self::assertSame(ComplianceBucket::Missing, $bucket);
    }
```

(сигнатуру `bucketFor` расширить norm/held аналогично резолверу статуса; точные имена бакетов взять из текущего enum `ComplianceBucket`.)

- [ ] **Step 2: Run** `cd app && vendor/bin/phpunit tests/Unit/Compliance/Application/ReadModel/ComplianceBucketResolverTest.php` → FAIL.

- [ ] **Step 3: Extend bucket resolver** — добавить ветку дефицита (held<norm → Missing) в начало, аналогично Task 7. Если дашборд считает бакеты **в SQL** (`ProfileComplianceRepository::findForDashboard`/overview), добавить в SQL сравнение `held_quantity < norm` (норма — колонка проекции `quantity`? проверить, как хранится норма в проекции; если норма в jsonb `quantity` — разложить amount отдельной колонкой ИЛИ считать бакеты в PHP на выборке). **Решение по умолчанию:** считать бакеты в PHP (как сейчас, если так), не усложняя SQL; held_quantity уже числовая колонка. Если SQL-агрегация обязательна — добавить числовую `norm_quantity` в проекцию в Task 6/9.

- [ ] **Step 4: Run** тесты бакета + функц. дашборда (`tests/Functional/Compliance/...Dashboard...`) → PASS.

- [ ] **Step 5: Commit**

```bash
git add app/src/Compliance/Application/ReadModel/ComplianceBucketResolver.php app/src/Compliance/Infrastructure/Repository/ProfileComplianceRepository.php app/tests/Unit/Compliance/Application/ReadModel/ComplianceBucketResolverTest.php
git commit -m "СИЗ: дашборд-бакет учитывает недовыдачу (на руках меньше нормы = требует выдачи)"
```

> Примечание: если `ComplianceBucketResolver`/норма в проекции устроены иначе, чем предполагает шаг 3, исполнитель сверяется с фактическим кодом и реализует эквивалент (дефицит → «требует выдачи»), сохраняя остальные бакеты.

---

## Task 11: DraftFormationService — черновик на дефицит

**Files:**
- Modify: `app/src/Compliance/Application/Service/DraftFormationService.php`
- Test: `app/tests/Functional/Compliance/Application/Service/DraftFormationServiceTest.php` (Modify/Create) или через `WriteOffFlowTest` (Task 13)

**Interfaces:**
- Consumes: `ProfileCompliance` (`heldOf`, obligations), `ComplianceBucketResolver`.
- Produces: `formForProfileRequirement` создаёт черновик, если по требованию **есть дефицит (held<норма у материальной) ИЛИ просрочено** и открытого черновика ещё нет. (`hasDue` расширяется дефицитом.)

- [ ] **Step 1: Write the failing test** (функциональный — через оформление акта списания; покрывается в Task 13 `WriteOffFlowTest`). Здесь — юнит/функц. на `hasDue`:

```php
    public function test_deficit_triggers_draft(): void
    {
        // enroll + issueCard норма 2, выдано 2 → списать 1 → оформить акт → held 1 < 2 → formForProfileRequirement создаёт черновик
        // (детали сборки — как в WriteOffFlowTest; ассерт: openDraftFor($r) !== null после оформления акта)
        self::markTestIncomplete('реализуется вместе с Task 13 (функц. флоу)');
    }
```

- [ ] **Step 2: Implement hasDue deficit**

В `DraftFormationService::hasDue(...)` (приватный помощник) добавить условие дефицита: для материальной обязанности требования `heldOf(key) < норма` → due (в дополнение к текущему bucket-overdue). Точную форму взять из текущего `hasDue` (он использует `ComplianceBucketResolver`); добавить дефицит-ветку.

- [ ] **Step 3: Run** функц. (после Task 13) `WriteOffFlowTest` → PASS. Отдельный `DraftFormationServiceTest` оставить как `markTestIncomplete` → заменить реальным в Task 13 либо здесь реализовать полноценный функц.-тест сборки.

- [ ] **Step 4: Commit**

```bash
git add app/src/Compliance/Application/Service/DraftFormationService.php
git commit -m "СИЗ: черновик выдачи заводится и на дефицит количества (не только по сроку)"
```

---

## Task 12: Команды и хендлеры (порции, причины, откат, подпись с дефицит-черновиком)

**Files:**
- Modify: `app/src/Compliance/Application/UseCase/Command/WriteOffPositions/WriteOffPositionsCommand.php` + `...Handler.php`
- Modify: `app/src/Compliance/Application/UseCase/Command/SetWriteOffReasons/SetWriteOffReasonsCommand.php` + `...Handler.php` (по id порции)
- Modify: `app/src/Compliance/Application/UseCase/Command/CancelWriteOffItem/CancelWriteOffItemCommand.php` + `...Handler.php` (по id порции)
- Modify: `app/src/Compliance/Application/UseCase/Command/SignWriteOffAct/SignWriteOffActCommandHandler.php` (requirementId из акта, после подписи — `formForProfileRequirement`)
- Modify: `app/src/Compliance/Application/Service/WriteOffActProjector.php` (порции: наименование из факта, количество, причина)
- Test: `app/tests/Functional/Compliance/Application/UseCase/WriteOffFlowTest.php` (Task 13)

**Interfaces:**
- `WriteOffPositionsCommand(string $profileId, string $requirementId, array $portions)` где `$portions = list<array{recordId:string, quantity:float}>`.
- `SetWriteOffReasonsCommand(string $profileId, string $writeOffActId, array $reasons)` где `$reasons = array<portionId, string>`.
- `CancelWriteOffItemCommand(string $profileId, string $writeOffActId, string $portionId)`.

- [ ] **Step 1: WriteOffPositionsCommand + handler**

Command:
```php
readonly class WriteOffPositionsCommand extends Command
{
    /** @param list<array{recordId: string, quantity: float}> $portions */
    public function __construct(public string $profileId, public string $requirementId, public array $portions) {}
}
```
Handler (`__invoke`): `canManage` → `findByProfile` → нормализовать порции (recordId непустой, quantity>0) → `$pc->writeOff(Uuid::v7(), $command->requirementId, $portions, new \DateTimeImmutable())` → `repository->add`. Пустой список → `AppException('Выберите, что списать.')`.

- [ ] **Step 2: SetWriteOffReasons + Cancel handlers** — перевести на `portionId` (ключ reasons = portionId; cancel принимает `portionId`). Домен уже под это (Task 4).

- [ ] **Step 3: SignWriteOffActCommandHandler** — найти `requirementId` акта среди `getWriteOffActs()`; `promote` скана; `signWriteOffAct(..., $this->calculator)`; `formation->formForProfileRequirement($pc, $requirementId, $actDate)`; `repository->add`; при ошибке — `storage->remove($fileId)`. (Снаружи команда без изменений — причины уже сохранены отдельной командой.)

- [ ] **Step 4: WriteOffActProjector** — строки из `itemsOfWriteOffAct`: `name` = `obligationLabelOf(fact.obligationKey)`, `qty` = порция.quantity (+ единица факта), `reason` = порция.reason.title(). Факт берём `recordById`-эквивалентом: проектор получает `WriteOffAct` → `act.profileCompliance()` → по `portion.recordId()` найти факт (добавить публичный `ProfileCompliance::recordById` ИЛИ отдать данные порции с наименованием из `obligationLabelOf` по ключу факта — для ключа нужен факт; сделать `recordById` публичным). Количество единицы: `fact.quantity()?->unit->title()`.

- [ ] **Step 5: Run** (после Task 13 тестов) — `./run check functional` раздел Compliance. Commit:

```bash
git add app/src/Compliance/Application/
git commit -m "СИЗ: команды списания порциями (кол-во/причина/откат по порции), подпись заводит черновик на дефицит, проектор по порциям"
```

---

## Task 13: Фронт — ввод количества, «на руках/дефицит», порции + функц.-тесты

**Files:**
- Modify: `app/src/Compliance/Infrastructure/Controller/Fulfillment/IssueAction.php`
- Modify: `app/src/Compliance/Infrastructure/Controller/Fulfillment/WriteOffAction.php` (приём порций {recordId, quantity})
- Modify: `app/src/Compliance/Infrastructure/Controller/WriteOffAct/ShowAction.php` + `RemoveItemAction.php` (порции по portionId, количество)
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/person/issue.html.twig` (режим списания: действующие выдачи-факты с «на руках N», поле количества, «Списать N»; оформление: предзаполнение дефицита)
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/writeoff/act.html.twig` (порции с количеством + причина + откат по portionId)
- Test: `app/tests/Functional/Compliance/Application/UseCase/WriteOffFlowTest.php` (Modify), `app/tests/Functional/Compliance/Infrastructure/Controller/CardDownloadControllerTest.php` (Modify — HTTP страниц)

**Interfaces:** контроллеры читают из формы `portions[i][recordId]`, `portions[i][quantity]` (списание) и `reasons[portionId]` (причины); карточка выдачи предзаполняет количество = дефицит = `норма − heldOf(key)`.

- [ ] **Step 1: Функц.-тест флоу (WriteOffFlowTest)** — переписать на порции:

```php
    public function test_partial_write_off_then_deficit_draft(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance(); // норма 2 (настроить enroll/или использовать имеющуюся)
        $this->issueCard($p, $r, $k); // выдано ≥ нормы
        $pc = $this->repo->findByProfile($p);
        $recordId = $pc->getRecords()[0]->getId();

        $this->commandBus->execute(new WriteOffPositionsCommand($p, $r, [['recordId' => $recordId, 'quantity' => 1.0]]));
        $this->reload();
        $pc = $this->repo->findByProfile($p);
        $actId = $pc->getWriteOffActs()[0]->getId();
        $portionId = $pc->itemsOfWriteOffAct($actId)[0]->getId();
        $this->commandBus->execute(new SetWriteOffReasonsCommand($p, $actId, [$portionId => 'physical_wear']));
        $this->commandBus->execute(new SignWriteOffActCommand($p, $actId, $p, [$p], '39', '2026-08-10', $this->stageComplianceScan()));

        $this->reload();
        $pc = $this->repo->findByProfile($p);
        self::assertTrue($pc->getWriteOffActs()[0]->isSigned());
        self::assertNotNull($pc->openDraftFor($r), 'дефицит → черновик новой выдачи');
    }
```

Прочие тесты файла (`docx renders`, и т.п.) привести к порционным сигнатурам. `enrollCompliance` настроить на материальную норму с количеством ≥ 2 (если сейчас 10 — ок, спишем 1, дефицит 1).

- [ ] **Step 2: Run** `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Functional/Compliance` → FAIL (контроллеры/шаблоны старые).

- [ ] **Step 3: IssueAction (режим списания)** — строки = **действующие выдачи-факты** материальных позиций: для каждого факта `held>0` отдать `recordId`, наименование (`obligationLabelOf`), `held` (heldAmount), единицу; признак «в черновике N» (порция открытого акта по recordId); дефицит позиции (`норма − heldOf(key)`). Для режима выдачи — предзаполнять количество дефицитом.

- [ ] **Step 4: WriteOffAction** — читать `portions[]` ({recordId, quantity}) из POST, собрать `list<array{recordId,quantity}>`, `WriteOffPositionsCommand($p,$r,$portions)`.

- [ ] **Step 5: WriteOffAct/ShowAction + RemoveItemAction** — порции: показать `portionId`, recordId→наименование, количество (поле/текст), причина-select по `reasons[portionId]`, откат по `portionId` (CancelWriteOffItem). Сохранить/Оформить как сейчас.

- [ ] **Step 6: Шаблоны** `issue.html.twig` (списание: факты с «на руках N», поле «Списать: [N]» ≤ held; оформление: value=дефицит) и `writeoff/act.html.twig` (порции: наименование + кол-во + причина + «Убрать» по portionId). Разметку копировать из текущих версий, менять только поля. CSS/JS не добавлять.

- [ ] **Step 7: CardDownloadControllerTest** — HTTP-тест страниц: GET карточки (режим списания) → есть форма с `name="portions[0][recordId]"` и полем количества; POST списания 1 → акт-черновик с порцией; GET страницы акта → `form#wo-act-form` с причиной порции; GET download → 200.

- [ ] **Step 8: Run** `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Functional/Compliance` → PASS. Commit:

```bash
git add app/src/Compliance/Infrastructure/Controller/ app/src/Shared/Infrastructure/Templates/admin/compliance/ app/tests/Functional/Compliance/
git commit -m "СИЗ фронт: списание порциями с вводом количества, на руках/дефицит, предзаполнение выдачи дефицитом"
```

---

## Task 14: Полный гейт + синхронизация dev-БД

**Files:** нет (операционная задача).

- [ ] **Step 1: Автофикс стиля** — `./run check style:fix`.

- [ ] **Step 2: Полный гейт** — `./run check`. Чинить phpstan (докблоки `@return list<...>`, импорты), unit, functional до зелёного.

- [ ] **Step 3: Синхронизировать dev-БД** — дельтой (прод пуст; dev держит прошлую схему). Выполнить в `manager_db`:
```sql
ALTER TABLE compliance_fulfillment_record DROP COLUMN IF EXISTS write_off_act_id;
ALTER TABLE compliance_fulfillment_record DROP COLUMN IF EXISTS write_off_reason;
ALTER TABLE compliance_fulfillment_record DROP COLUMN IF EXISTS returned_at;
ALTER TABLE compliance_fulfillment_record ALTER COLUMN returned_quantity TYPE DOUBLE PRECISION USING 0;
ALTER TABLE compliance_fulfillment_record ALTER COLUMN returned_quantity SET DEFAULT 0;
ALTER TABLE compliance_fulfillment_record ALTER COLUMN returned_quantity SET NOT NULL;
ALTER TABLE compliance_fulfillment_record ADD COLUMN IF NOT EXISTS document_id VARCHAR(36) DEFAULT NULL;
ALTER TABLE compliance_tracked_obligation ADD COLUMN IF NOT EXISTS held_quantity DOUBLE PRECISION NOT NULL DEFAULT 0;
DROP TABLE IF EXISTS compliance_write_off_item;
DROP TABLE IF EXISTS compliance_write_off_act;
-- пересоздать compliance_write_off_act + compliance_write_off_item как в миграции Version20261002120000 (без reason/lines на акте)
```
Затем `./run console cache:clear`. (Либо, если dev-данными можно пожертвовать — дропнуть Compliance-таблицы и прогнать миграции; но миграции помечены применёнными — надёжнее дельта.)

- [ ] **Step 4: Пересобрать проекцию held на dev** (у старых фактов held_quantity=0) — `./run console app:compliance:rebuild-projection` (пересчитает held из фактов).

- [ ] **Step 5: Финальный `./run check`** — всё зелёное. НЕ коммитить/пушить без явного апрува.

---

## Self-review (выполнено автором плана)

- **Покрытие спеки:** item (Task 1), порция (Task 2-3), writeOff/held/available/cancel/reasons (Task 4), эффект (Task 5), held в проекции (Task 6), статус (Task 7), assertIssuable (Task 8), ORM+миграция (Task 9), бакет дашборда (Task 10), дефицит-черновик (Task 11), команды/проектор (Task 12), фронт+функц. (Task 13), гейт+dev (Task 14). Все разделы спеки покрыты.
- **Типы/имена:** `writeOff(Uuid, string, list<array{recordId,quantity}>, now)`, `cancelWriteOffItem(actId, portionId, now)`, `applyWriteOffReasons(actId, array<portionId,WriteOffReason>, now)`, `signWriteOffAct(..., ObligationDueCalculator)`, `heldOf(key)`, `availableToWriteOff(recordId)`, `itemsOfWriteOffAct(actId): list<WriteOffItem>`, `TrackedObligation::heldQuantity/setHeldQuantity`, `WriteOffItem::recordId/quantity/addQuantity/reason/setReason` — согласованы между задачами.
- **Review Focus:** над-лимит (Task 4), повтор→+кол-во (Task 3/4), подпись без причины (Task 3/5), выдача ниже нормы (Task 8), нематериальная (Task 6/7) — у каждой есть тест.
- **Связка 5↔6:** `setHeldQuantity`/`recomputeObligation` — явно отмечено исполнителю выполнять Task 6 (поле) перед/вместе с Task 5 (эффект).
