<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Vehicle;

use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FuelTypeTest extends TestCase
{
    public function test_ai_95_is_restored_from_its_stored_value(): void
    {
        self::assertSame('АИ-95', FuelType::Ai95->value);
        self::assertSame(FuelType::Ai95, FuelType::from('АИ-95'));
    }

    #[DataProvider('additionalFuelTypes')]
    public function test_additional_fuel_type_is_restored_from_its_stored_value(string $stored, FuelType $expected): void
    {
        self::assertSame($stored, $expected->value);
        self::assertSame($expected, FuelType::from($stored));
    }

    /** @return iterable<string, array{string, FuelType}> */
    public static function additionalFuelTypes(): iterable
    {
        yield 'АИ-92' => ['АИ-92', FuelType::Ai92];
        yield 'АИ-98' => ['АИ-98', FuelType::Ai98];
        yield 'АИ-100' => ['АИ-100', FuelType::Ai100];
    }

    public function test_unknown_fuel_type_is_not_accepted(): void
    {
        self::assertNull(FuelType::tryFrom('неизвестное топливо'));
    }
}
