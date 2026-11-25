<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Infrastructure\Repository;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Infrastructure\Database\DBAL\DriverDetailsType;
use App\Waybills\Infrastructure\Database\DBAL\LocationType;
use App\Waybills\Infrastructure\Database\DBAL\WaybillFuelType;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillStorageTypesTest extends TestCase
{
    public function test_undated_fuel_total_driver_timezone_and_base_round_trip(): void
    {
        $platform = new PostgreSQLPlatform();
        $fuel = new Fuel(10, 7, new Refuelings(new PositiveNumber(5.5)), 'АИ-95');
        $driver = new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01', new \DateTimeZone('Europe/Berlin')), '11223344595');
        $base = new Location('Россия', 'Москва', 'Лесная', '1');
        $fuelType = new WaybillFuelType();
        $driverType = new DriverDetailsType();
        $locationType = new LocationType();

        $loadedFuel = $fuelType->convertToPHPValue($fuelType->convertToDatabaseValue($fuel, $platform), $platform);
        $loadedDriver = $driverType->convertToPHPValue($driverType->convertToDatabaseValue($driver, $platform), $platform);
        $loadedBase = $locationType->convertToPHPValue($locationType->convertToDatabaseValue($base, $platform), $platform);

        self::assertNotNull($loadedFuel);
        self::assertSame(5.5, $loadedFuel->refuelings()->total());
        self::assertSame([], $loadedFuel->refuelings()->entries());
        self::assertSame('Europe/Berlin', $loadedDriver?->licenseIssuedAt->getTimezone()->getName());
        self::assertSame('Россия, Москва, Лесная, 1', $loadedBase?->toString());
        self::assertSame('Москва', $loadedBase->city?->name);
    }

    public function test_invalid_fuel_shape_is_rejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new WaybillFuelType())->convertToPHPValue('{"initial":10,"remaining":"7","type":"АИ-95","refuelings":{"total":0,"entries":[]}}', new PostgreSQLPlatform());
    }

    public function test_invalid_domain_values_are_rejected_on_hydration(): void
    {
        $this->expectException(AppException::class);
        (new DriverDetailsType())->convertToPHPValue('{"fullName":" ","licenseNumber":"1234567890","licenseIssuedAt":"2020-01-01T00:00:00.000000+00:00|UTC","snils":"11223344595"}', new PostgreSQLPlatform());
    }

    public function test_previous_trip_from_another_vehicle_is_rejected(): void
    {
        $firstVehicle = $this->vehicle('A123BC77');
        $secondVehicle = $this->vehicle('B124CC77');
        $first = new RouteLog(Uuid::v7(), $firstVehicle, null, new \DateTimeImmutable('2026-01-01'), new Destination(new Location('Первая точка'), 1), RouteLogOrigin::Manual);

        $this->expectException(AppException::class);
        new RouteLog(Uuid::v7(), $secondVehicle, $first, new \DateTimeImmutable('2026-01-02'), new Destination(new Location('Вторая точка'), 1), RouteLogOrigin::Manual);
    }

    private function vehicle(string $registration): Vehicle
    {
        return new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber($registration), new \DateTimeImmutable('2025-01-01'), new Location('База'), 0, BigDecimal::zero());
    }
}
