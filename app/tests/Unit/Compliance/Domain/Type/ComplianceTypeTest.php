<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Type;

use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Compliance\Domain\ValueObject\Item\PeriodicItem;
use PHPUnit\Framework\TestCase;

final class ComplianceTypeTest extends TestCase
{
    public function test_titles(): void
    {
        self::assertSame('Выдача', ComplianceType::Material->title());
        self::assertSame('Периодическая', ComplianceType::Periodic->title());
    }

    public function test_requires_quantity(): void
    {
        self::assertTrue(ComplianceType::Material->requiresQuantity());
        self::assertTrue(ComplianceType::Periodic->requiresQuantity());
    }

    public function test_carries_journal(): void
    {
        self::assertFalse(ComplianceType::Material->carriesJournal());
        self::assertTrue(ComplianceType::Periodic->carriesJournal());
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

    public function test_periodic_makes_periodic_item_with_quantity(): void
    {
        $item = ComplianceType::Periodic->makeItem([
            'label' => 'Проверка знаний по электробезопасности',
            'cadence' => ['kind' => 'periodic', 'number' => 1, 'unit' => 'year'],
            'basis' => 'п.6 Правил',
            'quantity' => ['amount' => 1.0, 'unit' => 'pcs'],
        ]);

        self::assertInstanceOf(PeriodicItem::class, $item);
        self::assertSame(ComplianceType::Periodic, $item->type());
        self::assertSame(1.0, $item->quantity()->amount);
    }
}
