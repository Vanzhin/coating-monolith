<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\ValueObject\Item;

use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\MaterialMode;
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
            'itm-1',
            'Перчатки х/б',
            new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year),
            'п.5 Типовых норм',
            new Quantity(10.0, Unit::Pair),
        );
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
        $rebuilt = MaterialItem::fromArray($data);

        self::assertNotSame('', $rebuilt->id());
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
        new MaterialItem('itm-x', '   ', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), 'основание', new Quantity(1.0, Unit::Piece));
    }

    public function test_blank_basis_rejected(): void
    {
        $this->expectException(AppException::class);
        new MaterialItem('itm-x', 'Каска', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), '  ', new Quantity(1.0, Unit::Piece));
    }

    public function test_default_mode_is_inventoried(): void
    {
        self::assertSame(MaterialMode::Inventoried, $this->item()->mode());
    }

    public function test_mode_round_trips(): void
    {
        $consumable = new MaterialItem(
            'itm-2', 'Мыло', new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Month),
            'п.X', new Quantity(1200.0, Unit::Milliliter), MaterialMode::Consumable,
        );
        $rebuilt = MaterialItem::fromArray($consumable->jsonSerialize());

        self::assertSame(MaterialMode::Consumable, $rebuilt->mode());
        self::assertSame('consumable', $consumable->jsonSerialize()['mode']);
    }

    public function test_legacy_json_without_mode_defaults_inventoried(): void
    {
        $data = $this->item()->jsonSerialize();
        unset($data['mode']);

        self::assertSame(MaterialMode::Inventoried, MaterialItem::fromArray($data)->mode());
    }
}
