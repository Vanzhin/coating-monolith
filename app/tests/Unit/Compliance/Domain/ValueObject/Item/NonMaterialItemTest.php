<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\ValueObject\Item;

use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Item\NonMaterialItem;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class NonMaterialItemTest extends TestCase
{
    private function item(): NonMaterialItem
    {
        return new NonMaterialItem(
            'Инструктаж на рабочем месте',
            new Cadence(CadenceKind::Once),
            'ГОСТ 12.0.004',
        );
    }

    public function test_exposes_type_and_has_no_quantity(): void
    {
        $item = $this->item();

        self::assertSame(ComplianceType::NonMaterial, $item->type());
        self::assertSame('Инструктаж на рабочем месте', $item->label());
        self::assertArrayNotHasKey('quantity', $item->jsonSerialize());
    }

    public function test_round_trip_through_json(): void
    {
        $item = $this->item();

        self::assertEquals($item, NonMaterialItem::fromArray($item->jsonSerialize()));
    }

    public function test_blank_label_rejected(): void
    {
        $this->expectException(AppException::class);
        new NonMaterialItem('', new Cadence(CadenceKind::Once), 'основание');
    }
}
