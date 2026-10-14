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

    public function test_requirement_id_of_round_trips_with_key_of(): void
    {
        $key = TrackedObligation::keyOf('req-42', 'Перчатки');

        self::assertSame('req-42', TrackedObligation::requirementIdOf($key));
    }

    public function test_requirement_id_of_rejects_key_without_separator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TrackedObligation::requirementIdOf('нет-разделителя');
    }
}
