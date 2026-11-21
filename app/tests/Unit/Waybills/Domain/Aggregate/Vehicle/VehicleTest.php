<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Vehicle;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class VehicleTest extends TestCase
{
    public function test_creates_a_vehicle_with_fuels_and_optional_consumption_rate(): void
    {
        $id = Uuid::v7();
        $number = new RegistrationNumber('X960TA777');
        $rate = new PositiveNumber(8.5);
        $vehicle = new Vehicle($id, '  Skoda Octavia  ', $number, new \DateTimeImmutable('2026-01-01'), new Location('База'), 0, BigDecimal::zero(), $rate, FuelType::Ai95, FuelType::Ai95, FuelType::Ai98);

        self::assertSame((string) $id, $vehicle->getId());
        self::assertSame('Skoda Octavia', $vehicle->getModel());
        self::assertSame('x960ta777', $vehicle->getRegistrationNumber()->value());
        self::assertSame([FuelType::Ai95, FuelType::Ai98], $vehicle->getFuelTypes());
        self::assertSame(8.5, $vehicle->getConsumptionRate()?->value());
    }

    public function test_rate_is_optional_and_can_be_changed_or_removed(): void
    {
        $vehicle = $this->vehicle();
        self::assertNull($vehicle->getConsumptionRate());

        $vehicle->setConsumptionRate(new PositiveNumber(9.5));
        self::assertSame(9.5, $vehicle->getConsumptionRate()?->value());

        $vehicle->setConsumptionRate(null);
        self::assertNull($vehicle->getConsumptionRate());
    }

    public function test_vehicle_details_can_be_updated(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->rename(' New model ');
        $vehicle->changeRegistrationNumber(new RegistrationNumber('A123BC999'));
        $vehicle->changeFuelTypes(FuelType::Ai95, FuelType::Ai92, FuelType::Ai95);

        self::assertSame('New model', $vehicle->getModel());
        self::assertSame('a123bc999', $vehicle->getRegistrationNumber()->value());
        self::assertSame([FuelType::Ai92, FuelType::Ai95], $vehicle->getFuelTypes());
    }

    public function test_cannot_create_vehicle_with_empty_model(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('марку и модель');
        new Vehicle(Uuid::v7(), '  ', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2026-01-01'), new Location('База'), 0, BigDecimal::zero());
    }

    public function test_failed_model_change_preserves_previous_value(): void
    {
        $vehicle = $this->vehicle();
        try {
            $vehicle->rename(' ');
            self::fail('Пустая марка и модель должны отклоняться.');
        } catch (AppException) {
            self::assertSame('Модель', $vehicle->getModel());
        }
    }

    public function test_consumption_rate_cannot_be_infinite(): void
    {
        $this->expectException(AppException::class);
        new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2026-01-01'), new Location('База'), 0, BigDecimal::zero(), new PositiveNumber(INF));
    }

    public function test_consumption_rate_cannot_be_nan_when_changed(): void
    {
        $this->expectException(AppException::class);
        $this->vehicle()->setConsumptionRate(new PositiveNumber(NAN));
    }

    public function test_model_cannot_contain_only_unicode_whitespace(): void
    {
        $this->expectException(AppException::class);
        $this->vehicle()->rename("\u{00A0}");
    }

    public function test_fuel_type_cannot_be_blank(): void
    {
        self::assertNull(FuelType::tryFrom("\u{00A0}"));
    }

    private function vehicle(): Vehicle
    {
        return new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2026-01-01'), new Location('База'), 0, BigDecimal::zero());
    }
}
