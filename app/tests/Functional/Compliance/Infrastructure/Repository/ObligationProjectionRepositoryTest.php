<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Repository;

use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Repository\ObligationProjectionFilter;
use App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\MaterialMode;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ObligationProjectionRepositoryTest extends KernelTestCase
{
    private ObligationProjectionRepositoryInterface $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(ObligationProjectionRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $this->repo->deleteForProfile('p1');
        parent::tearDown();
    }

    public function test_upsert_then_find_by_filter_round_trips(): void
    {
        $this->repo->upsertMany([$this->rowArray(profileId: 'p1', requirementId: 'r1', itemId: 'i1', held: 2.0)]);
        $this->clear();

        $page = $this->repo->findByFilter(new ObligationProjectionFilter(Pager::fromPage(1, 10), profileIds: new StringCollection('p1')));

        self::assertCount(1, $page->items);
        self::assertSame('p1', $page->items[0]->profileId());
        self::assertSame(2.0, $page->items[0]->heldQuantity());
    }

    public function test_upsert_is_idempotent_on_profile_requirement_item(): void
    {
        $this->repo->upsertMany([$this->rowArray('p1', 'r1', 'i1', held: 2.0)]);
        $this->repo->upsertMany([$this->rowArray('p1', 'r1', 'i1', held: 5.0)]);
        $this->clear();

        $page = $this->repo->findByFilter(new ObligationProjectionFilter(Pager::fromPage(1, 10), profileIds: new StringCollection('p1')));
        self::assertCount(1, $page->items);
        self::assertSame(5.0, $page->items[0]->heldQuantity());
    }

    /**
     * Регрессия: `active => false` ломал `upsertMany` (DBAL без явного типа параметра биндит PHP bool
     * как ParameterType::STRING → PDO кастит false в "" → Postgres SQLSTATE 22P02 на boolean-колонке).
     * `true` случайно выживал (кастится в "1"), поэтому баг не поймали два теста выше. Заодно гоняет
     * непустую дату (last_fulfilled_at) — проверить, что строковый формат даты бьётся в TIMESTAMP штатно.
     */
    public function test_upsert_with_active_false_round_trips(): void
    {
        $this->repo->upsertMany([$this->rowArray('p1', 'r1', 'i2', held: 3.0, active: false, lastFulfilledAt: '2026-01-15 00:00:00')]);
        $this->clear();

        $page = $this->repo->findByFilter(new ObligationProjectionFilter(
            Pager::fromPage(1, 10),
            profileIds: new StringCollection('p1'),
            active: false,
        ));

        self::assertCount(1, $page->items);
        self::assertSame('p1', $page->items[0]->profileId());
        self::assertFalse($page->items[0]->isActive());
        self::assertSame(3.0, $page->items[0]->heldQuantity());
        self::assertEquals(new \DateTimeImmutable('2026-01-15 00:00:00'), $page->items[0]->lastFulfilledAt());
    }

    private function clear(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @return array<string, mixed>
     */
    private function rowArray(
        string $profileId,
        string $requirementId,
        string $itemId,
        float $held = 0.0,
        bool $active = true,
        ?string $lastFulfilledAt = null,
    ): array {
        return [
            'id' => (string) Uuid::v7(),
            'profile_id' => $profileId,
            'requirement_id' => $requirementId,
            'item_id' => $itemId,
            'requirement_name' => 'Тестовое требование',
            'label' => 'Тестовая позиция',
            'type' => ComplianceType::Material->value,
            'cadence' => 'periodic',
            'quantity' => 1.0,
            'department_id' => 'dep-1',
            'last_fulfilled_at' => $lastFulfilledAt,
            'next_due_at' => null,
            'held_quantity' => $held,
            'active' => $active,
            'origin' => TrackedObligation::ORIGIN_NORM,
            'mode' => MaterialMode::Inventoried->value,
        ];
    }
}
