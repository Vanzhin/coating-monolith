<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Repository;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\ObligationKey;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ProfileCompliancePersistenceTest extends KernelTestCase
{
    private ProfileComplianceRepositoryInterface $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(ProfileComplianceRepositoryInterface::class);
    }

    /**
     * Журнал фактов — ORM round-trip через {@see ProfileCompliance::recordFulfillment} (прямая дозапись, без
     * промежуточного акта). Норму (Вариант B) агрегат не несёт — её round-trip не здесь.
     */
    public function test_round_trip_facts(): void
    {
        $profileId = 'prof-'.uniqid('', true);
        $reqId = 'req-'.uniqid('', true);
        $itemId = 'item-'.uniqid('', true);
        $pc = new ProfileCompliance(Uuid::v7(), $profileId);
        $pc->recordFulfillment(
            Uuid::v7(), ObligationKey::keyOf($reqId, $itemId), new \DateTimeImmutable('2026-01-10'),
            new Quantity(10.0, Unit::Pair), new Percent(15),
        );
        $this->repo->add($pc);

        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $loaded = $this->repo->findByProfile($profileId);
        self::assertNotNull($loaded);
        self::assertCount(1, $loaded->getRecords());

        $rec = $loaded->getRecords()[0];
        self::assertSame('2026-01-10', $rec->fulfilledAt()->format('Y-m-d'));
        self::assertSame(10.0, $rec->quantity()?->amount);
        self::assertEqualsWithDelta(15.0, $rec->wearPercent()?->value(), 0.001);
    }

    /**
     * Round-trip декларации {@see \App\Compliance\Domain\Aggregate\ProfileCompliance\PersonalObligation}:
     * заводится на карточке явной кнопкой (вне нормы требования), переживает сохранение/перечитку ORM.
     */
    public function test_personal_obligation_declaration_survives_reload(): void
    {
        $profileId = 'prof-'.uniqid('', true);
        $reqId = 'req-'.uniqid('', true);
        $pc = new ProfileCompliance(Uuid::v7(), $profileId);
        $pc->addPersonalObligation(
            'item-1', $reqId, 'Перчатки Х', ComplianceType::Material,
            new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(1.0, Unit::Piece),
        );
        $this->repo->add($pc);

        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $reloaded = $this->repo->findByProfile($profileId);
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->personalObligations());
        $declaration = $reloaded->personalObligations()[0];
        self::assertSame('Перчатки Х', $declaration->label);
        self::assertSame($reqId, $declaration->requirementId);
        self::assertSame('item-1', $declaration->itemId);
        self::assertSame(ComplianceType::Material, $declaration->type);
        self::assertSame(CadenceKind::ByManufacturerDoc, $declaration->cadence->kind);
        self::assertSame(1.0, $declaration->quantity?->amount);
    }

    public function test_personal_obligation_without_quantity_survives_reload(): void
    {
        $profileId = 'prof-'.uniqid('', true);
        $reqId = 'req-'.uniqid('', true);
        $pc = new ProfileCompliance(Uuid::v7(), $profileId);
        $pc->addPersonalObligation(
            'item-2', $reqId, 'Инструктаж вне нормы', ComplianceType::Periodic,
            new Cadence(CadenceKind::ByManufacturerDoc), null,
        );
        $this->repo->add($pc);

        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $reloaded = $this->repo->findByProfile($profileId);
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->personalObligations());
        self::assertNull($reloaded->personalObligations()[0]->quantity);
    }
}
