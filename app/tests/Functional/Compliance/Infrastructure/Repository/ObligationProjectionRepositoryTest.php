<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Repository;

use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Repository\ObligationProjectionFilter;
use App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\MaterialMode;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Cadence;
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

    /**
     * Условия due-soon скана ({@see \App\Compliance\Application\Service\DueSoonScanner}) после флипа на проекцию:
     * active=true И next_due_at задан И не позже порога. Та же проверка, что раньше гоняла прямой SQL-скан
     * `compliance_tracked_obligation` ({@see \App\Compliance\Infrastructure\Repository\ProfileComplianceRepository::findDueSoonObligations},
     * снят этой задачей) — переносим на новую таблицу, чтобы условие не осталось непроверенным после флипа.
     */
    public function test_find_by_filter_active_and_due_before_matches_due_soon_scan_conditions(): void
    {
        $now = new \DateTimeImmutable('2027-01-01');
        $threshold = $now->modify('+60 days');

        $this->repo->upsertMany([
            $this->rowArray('p1', 'r1', 'due-soon', active: true, nextDueAt: $now->modify('+10 days')->format('Y-m-d H:i:s')),
            $this->rowArray('p1', 'r1', 'overdue', active: true, nextDueAt: $now->modify('-5 days')->format('Y-m-d H:i:s')),
            $this->rowArray('p1', 'r1', 'far-future', active: true, nextDueAt: $now->modify('+120 days')->format('Y-m-d H:i:s')),
            $this->rowArray('p1', 'r1', 'inactive', active: false, nextDueAt: $now->modify('+10 days')->format('Y-m-d H:i:s')),
            $this->rowArray('p1', 'r1', 'no-date', active: true, nextDueAt: null),
        ]);
        $this->clear();

        $page = $this->repo->findByFilter(new ObligationProjectionFilter(
            Pager::fromPage(1, 10),
            profileIds: new StringCollection('p1'),
            active: true,
            dueBefore: $threshold,
        ));

        $itemIds = array_map(static fn ($row): string => $row->itemId(), $page->items);
        sort($itemIds);
        self::assertSame(['due-soon', 'overdue'], $itemIds, 'далёкий срок/неактивная/без срока не должны пройти фильтр');
    }

    /**
     * `cadence_struct` (JSONB) реконструирует полный VO {@see Cadence} на чтении — не только label-снимок
     * {@see \App\Compliance\Infrastructure\Database\ReadModel\ObligationProjection::cadence()}. Нужно форме
     * выдачи для живого предпросмотра «след. выдача» (считает kind+число+единица, не готовую дату).
     */
    public function test_cadence_struct_round_trips_full_value_object(): void
    {
        $this->repo->upsertMany([$this->rowArray('p1', 'r1', 'i1', cadenceStruct: new Cadence(CadenceKind::Periodic, 2, PeriodUnit::Month))]);
        $this->clear();

        $page = $this->repo->findByFilter(new ObligationProjectionFilter(Pager::fromPage(1, 10), profileIds: new StringCollection('p1')));

        self::assertCount(1, $page->items);
        $cadence = $page->items[0]->cadenceStruct();
        self::assertNotNull($cadence);
        self::assertSame(CadenceKind::Periodic, $cadence->kind);
        self::assertSame(2, $cadence->number);
        self::assertSame(PeriodUnit::Month, $cadence->unit);
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
        ?string $nextDueAt = null,
        ?Cadence $cadenceStruct = null,
    ): array {
        $cadenceStruct ??= new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year);

        return [
            'id' => (string) Uuid::v7(),
            'profile_id' => $profileId,
            'requirement_id' => $requirementId,
            'item_id' => $itemId,
            'requirement_name' => 'Тестовое требование',
            'label' => 'Тестовая позиция',
            'type' => ComplianceType::Material->value,
            'cadence' => 'periodic',
            'cadence_struct' => json_encode($cadenceStruct->jsonSerialize(), JSON_THROW_ON_ERROR),
            'quantity' => 1.0,
            'quantity_unit' => 'pcs',
            'department_id' => 'dep-1',
            'last_fulfilled_at' => $lastFulfilledAt,
            'next_due_at' => $nextDueAt,
            'held_quantity' => $held,
            'active' => $active,
            'origin' => TrackedObligation::ORIGIN_NORM,
            'mode' => MaterialMode::Inventoried->value,
        ];
    }
}
