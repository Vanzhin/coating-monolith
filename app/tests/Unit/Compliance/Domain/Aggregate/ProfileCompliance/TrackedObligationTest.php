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
            Uuid::v7(), $this->createStub(ProfileCompliance::class), 'req-1', 'item-1', 'Норма', 'Мыло',
            ComplianceType::Material, new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Month),
            new Quantity(1200.0, Unit::Milliliter), 'dep-1', TrackedObligation::ORIGIN_NORM, MaterialMode::Consumable,
        );

        self::assertSame(MaterialMode::Consumable, $o->mode());
        self::assertTrue($o->isConsumable());
    }

    public function test_non_material_has_no_mode(): void
    {
        $o = new TrackedObligation(
            Uuid::v7(), $this->createStub(ProfileCompliance::class), 'req-2', 'item-2', 'Инструктаж', 'ТБ',
            ComplianceType::NonMaterial, new Cadence(CadenceKind::Once, null, null),
            null, 'dep-1', TrackedObligation::ORIGIN_NORM, null,
        );

        self::assertNull($o->mode());
        self::assertFalse($o->isConsumable());
    }

    public function test_key_is_requirement_id_plus_item_id(): void
    {
        $o = $this->obligation(requirementId: 'req-42', itemId: 'item-7', label: 'Перчатки');

        self::assertSame('req-42|item-7', $o->key());
        self::assertSame('item-7', $o->itemId());
    }

    public function test_requirement_id_is_prefix_before_pipe(): void
    {
        self::assertSame('req-42', TrackedObligation::requirementIdOf('req-42|item-7'));
    }

    public function test_requirement_id_of_throws_without_separator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TrackedObligation::requirementIdOf('нет-разделителя');
    }

    private function obligation(string $requirementId = 'req-1', string $itemId = 'item-1', string $label = 'Перчатки'): TrackedObligation
    {
        return new TrackedObligation(
            Uuid::v7(), $this->createStub(ProfileCompliance::class), $requirementId, $itemId, 'Норма', $label,
            ComplianceType::Material, new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            null, 'dep-1',
        );
    }
}
