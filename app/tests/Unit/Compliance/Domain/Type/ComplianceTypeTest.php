<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Type;

use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Compliance\Domain\ValueObject\Item\NonMaterialItem;
use PHPUnit\Framework\TestCase;

final class ComplianceTypeTest extends TestCase
{
    public function test_titles(): void
    {
        self::assertSame('Выдача', ComplianceType::Material->title());
        self::assertSame('Процедура', ComplianceType::NonMaterial->title());
    }

    public function test_material_makes_material_item_with_quantity(): void
    {
        $item = ComplianceType::Material->makeItem([
            'label' => 'Перчатки',
            'cadence' => ['kind' => 'periodic', 'number' => 1, 'unit' => 'year'],
            'basis' => 'п.5 Типовых норм',
            'quantity' => ['amount' => 10.0, 'unit' => 'pair'],
        ]);

        self::assertInstanceOf(MaterialItem::class, $item);
        self::assertSame(ComplianceType::Material, $item->type());
        self::assertSame(10.0, $item->quantity()->amount);
    }

    public function test_non_material_makes_non_material_item(): void
    {
        $item = ComplianceType::NonMaterial->makeItem([
            'label' => 'Инструктаж на рабочем месте',
            'cadence' => ['kind' => 'once', 'number' => null],
            'basis' => 'ГОСТ 12.0.004',
        ]);

        self::assertInstanceOf(NonMaterialItem::class, $item);
        self::assertSame(ComplianceType::NonMaterial, $item->type());
    }
}
