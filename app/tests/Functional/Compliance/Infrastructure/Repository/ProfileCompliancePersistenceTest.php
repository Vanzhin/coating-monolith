<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Repository;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ProfileCompliancePersistenceTest extends KernelTestCase
{
    private ProfileComplianceRepositoryInterface $repo;
    private ObligationDueCalculator $calc;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(ProfileComplianceRepositoryInterface::class);
        $this->calc = new ObligationDueCalculator();
    }

    public function test_round_trip_projection_and_facts(): void
    {
        $profileId = 'prof-'.uniqid('', true);
        $reqId = 'req-'.uniqid('', true);
        $pc = new ProfileCompliance(Uuid::v7(), $profileId);
        $pc->putObligation(new TrackedObligation(
            Uuid::v7(), $pc, $reqId, 'СИЗ основные', 'Перчатки',
            ComplianceType::Material, new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            new Quantity(10.0, Unit::Pair), 'dept-1',
        ));
        $pc->recordFulfillment(
            Uuid::v7(), TrackedObligation::keyOf($reqId, 'Перчатки'), new \DateTimeImmutable('2026-01-10'),
            $this->calc, new Quantity(10.0, Unit::Pair), new Percent(15),
        );
        $pc->setActiveForRequirement($reqId, true);
        $this->repo->add($pc);

        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $loaded = $this->repo->findByProfile($profileId);
        self::assertNotNull($loaded);
        self::assertCount(1, $loaded->getObligations());
        self::assertCount(1, $loaded->getRecords());

        $ob = $loaded->getObligations()[0];
        self::assertSame('Перчатки', $ob->label());
        self::assertSame(ComplianceType::Material, $ob->type());
        self::assertSame(CadenceKind::Periodic, $ob->cadence()->kind);
        self::assertSame(PeriodUnit::Year, $ob->cadence()->unit);
        self::assertSame(10.0, $ob->quantity()?->amount);
        self::assertSame('2026-01-10', $ob->lastFulfilledAt()?->format('Y-m-d'));
        self::assertSame('2027-01-10', $ob->nextDueAt()?->format('Y-m-d'));
        self::assertTrue($ob->isActive());

        $rec = $loaded->getRecords()[0];
        self::assertSame('2026-01-10', $rec->fulfilledAt()->format('Y-m-d'));
        self::assertSame(10.0, $rec->quantity()?->amount);
        self::assertEqualsWithDelta(15.0, $rec->wearPercent()?->value(), 0.001);
    }

    public function test_active_flag_persists(): void
    {
        $profileId = 'prof-'.uniqid('', true);
        $reqId = 'req-'.uniqid('', true);
        $pc = new ProfileCompliance(Uuid::v7(), $profileId);
        $pc->putObligation(new TrackedObligation(
            Uuid::v7(), $pc, $reqId, 'Журнал', 'Инструктаж',
            ComplianceType::NonMaterial, new Cadence(CadenceKind::Once), null, 'dept-1',
        ));
        $this->repo->add($pc);

        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $loaded = $this->repo->findByProfile($profileId);
        self::assertNotNull($loaded);
        $ob = $loaded->getObligations()[0];
        self::assertFalse($ob->isActive()); // документ не подписан
        self::assertNull($ob->quantity());
        self::assertSame(CadenceKind::Once, $ob->cadence()->kind);
    }
}
