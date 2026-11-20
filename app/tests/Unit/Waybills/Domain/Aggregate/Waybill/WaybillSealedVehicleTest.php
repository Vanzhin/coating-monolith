<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Waybill;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillSealedVehicleTest extends TestCase
{
    public function test_snapshot_does_not_change_with_vehicle(): void
    {
        $id = Uuid::v7();
        $vehicle = new Vehicle($id, 'Старая модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2026-01-01'), new Location('База'), 0, BigDecimal::zero(), new PositiveNumber(8.5), FuelType::Ai95, FuelType::Ai95);
        $vehicle->enter(new DriverProfile(Uuid::v7(), 'owner', new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), new Location('База')));
        $trip = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Назначение'), 1), RouteLogOrigin::Manual);
        $waybill = Waybill::create(Uuid::v7(), 1, $vehicle, WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')), 1, new Fuel(0, 0, new Refuelings(), 'АИ-95'), new Route($vehicle, $trip, $trip));
        $waybill->lock();

        $vehicle->rename('Новая модель');
        $vehicle->changeRegistrationNumber(new RegistrationNumber('B124CC77'));
        $vehicle->changeFuelTypes(FuelType::Ai92, FuelType::Ai92);
        $vehicle->setConsumptionRate(new PositiveNumber(10));

        self::assertSame((string) $id, $waybill->vehicleId());
        self::assertSame('Старая модель', $waybill->vehicleModel());
        self::assertSame('a123bc77', $waybill->vehicleRegistrationNumber()->value());
        self::assertSame([FuelType::Ai92], $waybill->vehicleFuelTypes());
        self::assertSame('АИ-95', $waybill->fuel()->type());
        self::assertSame(8.5, $waybill->vehicleConsumptionRate()?->value());
    }
}
