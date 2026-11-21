<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Vehicle;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class VehicleFuelTypesTest extends TestCase
{
    public function test_new_vehicle_defaults_to_ai_95(): void
    {
        $vehicle = $this->vehicle();

        self::assertSame(FuelType::Ai95, $vehicle->getDefaultFuelType());
        self::assertSame([FuelType::Ai95], $vehicle->getFuelTypes());
    }

    public function test_vehicle_supports_multiple_fuel_types_with_selected_default(): void
    {
        $vehicle = $this->vehicle(FuelType::Ai98, FuelType::Ai92, FuelType::Ai98, FuelType::Ai100);

        self::assertSame(FuelType::Ai98, $vehicle->getDefaultFuelType());
        self::assertSame([FuelType::Ai92, FuelType::Ai98, FuelType::Ai100], $vehicle->getFuelTypes());
    }

    public function test_explicit_default_can_be_the_only_supported_type(): void
    {
        $vehicle = $this->vehicle(FuelType::Ai92);

        self::assertSame(FuelType::Ai92, $vehicle->getDefaultFuelType());
        self::assertSame([FuelType::Ai92], $vehicle->getFuelTypes());
    }

    public function test_cannot_construct_vehicle_with_default_outside_supported_types(): void
    {
        $this->expectException(AppException::class);
        $this->vehicle(FuelType::Ai95, FuelType::Ai92, FuelType::Ai98);
    }

    public function test_changing_supported_types_and_default_is_atomic(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->changeFuelTypes(FuelType::Ai98, FuelType::Ai92, FuelType::Ai98);

        self::assertSame(FuelType::Ai98, $vehicle->getDefaultFuelType());
        self::assertSame([FuelType::Ai92, FuelType::Ai98], $vehicle->getFuelTypes());

        try {
            $vehicle->changeFuelTypes(FuelType::Ai100, FuelType::Ai92);
            self::fail('Топливо по умолчанию должно поддерживаться автомобилем.');
        } catch (AppException) {
            self::assertSame(FuelType::Ai98, $vehicle->getDefaultFuelType());
            self::assertSame([FuelType::Ai92, FuelType::Ai98], $vehicle->getFuelTypes());
        }
    }

    public function test_vehicle_does_not_duplicate_supported_types(): void
    {
        $vehicle = $this->vehicle(FuelType::Ai95, FuelType::Ai95, FuelType::Ai92, FuelType::Ai95);

        self::assertSame([FuelType::Ai95, FuelType::Ai92], $vehicle->getFuelTypes());
    }

    private function vehicle(FuelType $default = FuelType::Ai95, FuelType ...$types): Vehicle
    {
        return new Vehicle(
            Uuid::v7(),
            'Модель',
            new RegistrationNumber('A123BC77'),
            new \DateTimeImmutable('2025-01-01 00:00:00+00:00'),
            new Location('А'),
            100,
            BigDecimal::of('20'),
            null,
            $default,
            ...$types,
        );
    }
}
