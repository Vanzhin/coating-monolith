<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Aggregate\ProfileCompliance\PersonalObligation;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use PHPUnit\Framework\TestCase;

final class PersonalObligationTest extends TestCase
{
    public function test_json_round_trip_with_quantity(): void
    {
        $obligation = new PersonalObligation(
            'item-1', 'req-1', 'Очки', ComplianceType::Material,
            new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(1.0, Unit::Piece),
        );

        $restored = PersonalObligation::fromArray($obligation->jsonSerialize());

        self::assertSame($obligation->itemId, $restored->itemId);
        self::assertSame($obligation->requirementId, $restored->requirementId);
        self::assertSame($obligation->label, $restored->label);
        self::assertSame($obligation->type, $restored->type);
        self::assertSame($obligation->cadence->kind, $restored->cadence->kind);
        self::assertEqualsWithDelta($obligation->quantity?->amount, $restored->quantity?->amount, 1e-9);
        self::assertSame($obligation->quantity?->unit, $restored->quantity?->unit);
    }

    public function test_json_round_trip_without_quantity(): void
    {
        $obligation = new PersonalObligation(
            'item-2', 'req-1', 'Инструктаж вне нормы', ComplianceType::NonMaterial,
            new Cadence(CadenceKind::ByManufacturerDoc), null,
        );

        $restored = PersonalObligation::fromArray($obligation->jsonSerialize());

        self::assertNull($restored->quantity);
        self::assertSame('Инструктаж вне нормы', $restored->label);
        self::assertSame(ComplianceType::NonMaterial, $restored->type);
    }

    public function test_json_serialize_shape(): void
    {
        $obligation = new PersonalObligation(
            'item-3', 'req-2', 'Фартук', ComplianceType::Material,
            new Cadence(CadenceKind::ByManufacturerDoc), new Quantity(2.0, Unit::Piece),
        );

        $json = $obligation->jsonSerialize();

        self::assertSame('item-3', $json['itemId']);
        self::assertSame('req-2', $json['requirementId']);
        self::assertSame('Фартук', $json['label']);
        self::assertSame('material', $json['type']);
        self::assertSame('by_manufacturer_doc', $json['cadence']['kind']);
        self::assertSame(2.0, $json['quantity']['amount']);
        self::assertSame('pcs', $json['quantity']['unit']);
    }
}
