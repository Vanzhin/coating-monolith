<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\ValueObject\Item;

use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Item\PeriodicItem;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class PeriodicItemTest extends TestCase
{
    private function item(): PeriodicItem
    {
        return new PeriodicItem(
            'itm-1',
            'Инструктаж по электробезопасности',
            new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            'п.6',
            new Quantity(1.0, Unit::Piece),
        );
    }

    public function test_requires_positive_quantity(): void
    {
        $this->expectException(AppException::class);
        new PeriodicItem('i1', 'Инструктаж', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), 'п.6', new Quantity(0.0, Unit::Piece));
    }

    public function test_round_trips_through_array(): void
    {
        $item = new PeriodicItem('i1', 'Крем', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), 'п.9', new Quantity(1200.0, Unit::Milliliter));

        self::assertEquals($item, PeriodicItem::fromArray($item->jsonSerialize()));
        self::assertSame(ComplianceType::Periodic, $item->type());
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
        $rebuilt = PeriodicItem::fromArray($data);

        self::assertNotSame('', $rebuilt->id());
    }

    public function test_exposes_type_and_fields(): void
    {
        $item = $this->item();

        self::assertSame(ComplianceType::Periodic, $item->type());
        self::assertSame('Инструктаж по электробезопасности', $item->label());
        self::assertSame('п.6', $item->basis());
        self::assertSame(CadenceKind::Periodic, $item->cadence()->kind);
        self::assertSame(1.0, $item->quantity()->amount);
    }

    public function test_has_no_mode_key_in_json(): void
    {
        self::assertArrayNotHasKey('mode', $this->item()->jsonSerialize());
    }

    public function test_blank_label_rejected(): void
    {
        $this->expectException(AppException::class);
        new PeriodicItem('itm-x', '   ', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), 'основание', new Quantity(1.0, Unit::Piece));
    }

    public function test_blank_basis_rejected(): void
    {
        $this->expectException(AppException::class);
        new PeriodicItem('itm-x', 'Проверка знаний', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), '  ', new Quantity(1.0, Unit::Piece));
    }
}
