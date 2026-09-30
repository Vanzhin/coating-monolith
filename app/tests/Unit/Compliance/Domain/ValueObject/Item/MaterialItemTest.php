<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\ValueObject\Item;

use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class MaterialItemTest extends TestCase
{
    private function item(): MaterialItem
    {
        return new MaterialItem(
            'Перчатки х/б',
            new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            'п.5 Типовых норм',
            new Quantity(10.0, Unit::Pair),
        );
    }

    public function test_exposes_type_and_fields(): void
    {
        $item = $this->item();

        self::assertSame(ComplianceType::Material, $item->type());
        self::assertSame('Перчатки х/б', $item->label());
        self::assertSame('п.5 Типовых норм', $item->basis());
        self::assertSame(CadenceKind::Periodic, $item->cadence()->kind);
        self::assertSame(10.0, $item->quantity()->amount);
    }

    public function test_round_trip_through_json(): void
    {
        $item = $this->item();
        $rebuilt = MaterialItem::fromArray($item->jsonSerialize());

        self::assertEquals($item, $rebuilt);
        self::assertArrayHasKey('quantity', $item->jsonSerialize());
    }

    public function test_blank_label_rejected(): void
    {
        $this->expectException(AppException::class);
        new MaterialItem('   ', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), 'основание', new Quantity(1.0, Unit::Piece));
    }

    public function test_blank_basis_rejected(): void
    {
        $this->expectException(AppException::class);
        new MaterialItem('Каска', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), '  ', new Quantity(1.0, Unit::Piece));
    }
}
