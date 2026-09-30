<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Service\ComplianceStatusResolver;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceStatus;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProfileComplianceTest extends TestCase
{
    private ObligationDueCalculator $calc;
    private ComplianceStatusResolver $resolver;
    private \DateTimeImmutable $now;
    private string $reqId = 'req-1';

    protected function setUp(): void
    {
        $this->calc = new ObligationDueCalculator();
        $this->resolver = new ComplianceStatusResolver();
        $this->now = new \DateTimeImmutable('2026-06-01');
    }

    private function pcWithGloves(): ProfileCompliance
    {
        $pc = new ProfileCompliance(Uuid::v4(), 'prof-1');
        $pc->putObligation(new TrackedObligation(
            Uuid::v4(), $pc, $this->reqId, 'СИЗ основные', 'Перчатки',
            ComplianceType::Material, new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            new Quantity(10.0, Unit::Pair), 'dept-1',
        ));

        return $pc;
    }

    private function glovesKey(): string
    {
        return TrackedObligation::keyOf($this->reqId, 'Перчатки');
    }

    public function test_unsigned_is_red_even_when_fulfilled(): void
    {
        $pc = $this->pcWithGloves();
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc);

        // Документ не подписан (active=false по умолчанию) → не исполнено, несмотря на выдачу.
        self::assertSame(ComplianceStatus::Red, $pc->worstStatus($this->resolver, null, $this->now));
    }

    public function test_fulfillment_recomputes_dates(): void
    {
        $pc = $this->pcWithGloves();
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc);

        $ob = $pc->getObligations()[0];
        self::assertSame('2026-01-10', $ob->lastFulfilledAt()?->format('Y-m-d'));
        self::assertSame('2027-01-10', $ob->nextDueAt()?->format('Y-m-d'));
    }

    public function test_signed_and_fulfilled_far_is_green(): void
    {
        $pc = $this->pcWithGloves();
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc);
        $pc->setActiveForRequirement($this->reqId, true);

        self::assertSame(ComplianceStatus::Green, $pc->worstStatus($this->resolver, null, $this->now));
    }

    public function test_removing_last_record_rolls_back_dates_and_status(): void
    {
        $pc = $this->pcWithGloves();
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc);
        $pc->setActiveForRequirement($this->reqId, true);

        $recId = $pc->getRecords()[0]->getId();
        $pc->removeRecord($recId, $this->calc);

        self::assertNull($pc->getObligations()[0]->lastFulfilledAt());
        self::assertNull($pc->getObligations()[0]->nextDueAt());
        self::assertSame(ComplianceStatus::Red, $pc->worstStatus($this->resolver, null, $this->now)); // active, но не выдано
    }

    public function test_type_filter_in_worst_status(): void
    {
        $pc = $this->pcWithGloves();
        $pc->setActiveForRequirement($this->reqId, true);
        $pc->recordFulfillment(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), $this->calc);

        self::assertSame(ComplianceStatus::Green, $pc->worstStatus($this->resolver, ComplianceType::Material, $this->now));
        self::assertSame(ComplianceStatus::Green, $pc->worstStatus($this->resolver, ComplianceType::NonMaterial, $this->now)); // нет нематериальных — худший остаётся Green
    }

    public function test_exclude_removes_obligation(): void
    {
        $pc = $this->pcWithGloves();
        $pc->excludeObligation($this->glovesKey());

        self::assertCount(0, $pc->getObligations());
        self::assertContains($this->glovesKey(), $pc->getExcludedKeys()->getList());
    }
}
