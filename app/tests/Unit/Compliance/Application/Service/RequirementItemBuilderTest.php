<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Application\Service;

use App\Compliance\Application\Service\RequirementItemBuilder;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Compliance\Domain\ValueObject\Item\PeriodicItem;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class RequirementItemBuilderTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Перчатки', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1',
            'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5',
        ], $overrides);
    }

    public function test_generates_id_when_absent(): void
    {
        $items = (new RequirementItemBuilder())->build(ComplianceType::Material, [$this->row()]);

        self::assertCount(1, $items);
        self::assertNotSame('', $items[0]->id());
    }

    public function test_preserves_existing_id(): void
    {
        $items = (new RequirementItemBuilder())->build(ComplianceType::Material, [$this->row(['id' => 'keep-me'])]);

        self::assertSame('keep-me', $items[0]->id());
    }

    public function test_material_row_builds_material_item(): void
    {
        $items = (new RequirementItemBuilder())->build(ComplianceType::Material, [$this->row()]);

        self::assertInstanceOf(MaterialItem::class, $items[0]);
        self::assertSame(10.0, $items[0]->quantity()->amount);
    }

    public function test_builds_periodic_item_with_quantity(): void
    {
        $items = (new RequirementItemBuilder())->build(ComplianceType::Periodic, [$this->row()]);

        self::assertInstanceOf(PeriodicItem::class, $items[0]);
        self::assertSame(10.0, $items[0]->quantity()->amount);
    }

    public function test_periodic_without_quantity_raises_app_exception(): void
    {
        $this->expectException(AppException::class);
        (new RequirementItemBuilder())->build(ComplianceType::Periodic, [$this->row(['amount' => ''])]);
    }

    public function test_per_row_type_overrides_requirement_type(): void
    {
        $items = (new RequirementItemBuilder())->build(ComplianceType::Material, [
            $this->row(['label' => 'Каска', 'type' => 'material']),
            $this->row(['label' => 'Мыло', 'type' => 'periodic']),
        ]);

        self::assertInstanceOf(MaterialItem::class, $items[0]);
        self::assertInstanceOf(PeriodicItem::class, $items[1]);
    }

    public function test_row_without_type_falls_back_to_requirement_type(): void
    {
        $items = (new RequirementItemBuilder())->build(ComplianceType::Periodic, [$this->row()]);

        self::assertInstanceOf(PeriodicItem::class, $items[0]);
    }
}
