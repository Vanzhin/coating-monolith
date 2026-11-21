<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Waybill;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;
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
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillSealTest extends TestCase
{
    public function test_draft_uses_current_vehicle_and_driver_data(): void
    {
        [$waybill, $vehicle, $driver] = $this->draft();
        self::assertSame($vehicle, $waybill->vehicle());

        $vehicle->rename('Новая модель');
        $vehicle->changeRegistrationNumber(new RegistrationNumber('B124CC77'));
        $vehicle->changeFuelTypes(FuelType::Ai92, FuelType::Ai92);
        $vehicle->setConsumptionRate(new PositiveNumber(10));
        $driver->changeDriverDetails($this->details('Новые реквизиты'));
        $driver->changeBase(new Location('Новая база'));

        self::assertSame('Новая модель', $waybill->vehicleModel());
        self::assertSame('b124cc77', $waybill->vehicleRegistrationNumber()->value());
        self::assertSame([FuelType::Ai92], $waybill->vehicleFuelTypes());
        self::assertSame(10, $waybill->vehicleConsumptionRate()?->value());
        self::assertSame('Новые реквизиты', $waybill->driver()?->fullName);
        self::assertSame('Новая база', $waybill->base()?->toString());

        $vehicle->leave($driver);
        self::assertNull($waybill->driver());
        self::assertNull($waybill->base());

        $newDriver = $this->driver('second', 'Другой водитель', 'Другая база');
        $vehicle->enter($newDriver);
        self::assertSame('Другой водитель', $waybill->driver()->fullName);
        self::assertSame('Другая база', $waybill->base()->toString());
    }

    public function test_approval_freezes_current_document_data_but_keeps_vehicle_reference(): void
    {
        [$waybill, $vehicle, $driver] = $this->draft();
        $vehicle->leave($driver);
        $currentDriver = $this->driver('second', 'Водитель при утверждении', 'База при утверждении');
        $vehicle->enter($currentDriver);
        $vehicle->rename('Модель при утверждении');
        $vehicle->changeRegistrationNumber(new RegistrationNumber('B124CC77'));
        $vehicle->changeFuelTypes(FuelType::Ai95, FuelType::Ai92, FuelType::Ai95);
        $vehicle->setConsumptionRate(new PositiveNumber(10));
        $approvalDetails = $currentDriver->getDriverDetails();
        $this->complete($waybill, $vehicle);

        $waybill->lock();

        $vehicle->rename('Другая модель');
        $vehicle->changeRegistrationNumber(new RegistrationNumber('M001MM797'));
        $vehicle->changeFuelTypes(FuelType::Ai92, FuelType::Ai92);
        $vehicle->setConsumptionRate(new PositiveNumber(20));
        $currentDriver->changeDriverDetails(new DriverDetails('Изменённые реквизиты', '9876543210', new \DateTimeImmutable('2024-01-01'), '00000000100'));
        $currentDriver->changeBase(new Location('Изменённая база'));
        $vehicle->leave($currentDriver);
        $vehicle->enter($this->driver('third', 'Следующий водитель', 'Следующая база'));

        self::assertTrue($waybill->isLocked());
        self::assertSame($vehicle, $waybill->vehicle());
        self::assertSame('Другая модель', $waybill->vehicle()->getModel());
        self::assertSame('Модель при утверждении', $waybill->vehicleModel());
        self::assertSame('b124cc77', $waybill->vehicleRegistrationNumber()->value());
        self::assertSame([FuelType::Ai92], $waybill->vehicleFuelTypes());
        self::assertSame('АИ-95', $waybill->fuel()->type());
        self::assertSame(10, $waybill->vehicleConsumptionRate()?->value());
        self::assertSame($approvalDetails, $waybill->driver());
        self::assertSame('Водитель при утверждении', $waybill->driver()->fullName);
        self::assertSame('1234567890', $waybill->driver()->licenseNumber->value());
        self::assertSame('2020-01-01', $waybill->driver()->licenseIssuedAt->format('Y-m-d'));
        self::assertSame('11223344595', $waybill->driver()->snils->value());
        self::assertSame('База при утверждении', $waybill->base()?->toString());
    }

    public function test_absent_consumption_rate_remains_absent_after_approval(): void
    {
        [$waybill, $vehicle] = $this->draft();
        $vehicle->setConsumptionRate(null);
        $this->complete($waybill, $vehicle);
        $waybill->lock();
        $vehicle->setConsumptionRate(new PositiveNumber(12));

        self::assertNull($waybill->vehicleConsumptionRate());
        self::assertNull($waybill->consumptionByNorm());
    }

    public function test_cannot_approve_without_current_driver(): void
    {
        [$waybill, $vehicle, $driver] = $this->draft();
        $this->complete($waybill, $vehicle);
        $vehicle->leave($driver);

        try {
            $waybill->lock();
            self::fail('Нельзя утвердить лист без водителя.');
        } catch (AppException $exception) {
            self::assertStringContainsString('без водителя', $exception->getMessage());
        }
        self::assertFalse($waybill->isLocked());
        self::assertNull($waybill->driver());

        $vehicle->enter($this->driver('second', 'Новый водитель', 'Новая база'));
        $waybill->lock();
        self::assertSame('Новый водитель', $waybill->driver()?->fullName);
    }

    public function test_approval_rechecks_fuel_types_of_current_vehicle(): void
    {
        [$waybill, $vehicle] = $this->draft();
        $this->complete($waybill, $vehicle);
        $vehicle->changeFuelTypes(FuelType::Ai92, FuelType::Ai92);

        try {
            $waybill->lock();
            self::fail('Лист с неподдерживаемым топливом нельзя утвердить.');
        } catch (AppException $exception) {
            self::assertStringContainsString('марка топлива', $exception->getMessage());
        }
        self::assertFalse($waybill->isLocked());
        self::assertSame([FuelType::Ai92], $waybill->vehicleFuelTypes());
    }

    /** @return array{Waybill, Vehicle, DriverProfile} */
    private function draft(): array
    {
        $vehicle = new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2025-01-01'), new Location('База'), 100, BigDecimal::of('30'), new PositiveNumber(8));
        $driver = $this->driver('first', 'Первый водитель', 'Первая база');
        $vehicle->enter($driver);
        $period = new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));

        return [Waybill::createDraft(Uuid::v7(), 1, $vehicle, $period), $vehicle, $driver];
    }

    private function complete(Waybill $waybill, Vehicle $vehicle): void
    {
        $trip = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Назначение'), 3), RouteLogOrigin::Manual);
        $waybill->revise($waybill->period(), 103, new Fuel(30, 25, new Refuelings(), 'АИ-95'), new Route($vehicle, $trip, $trip));
    }

    private function driver(string $owner, string $name, string $base): DriverProfile
    {
        return new DriverProfile(Uuid::v7(), $owner, $this->details($name), new Location($base));
    }

    private function details(string $name): DriverDetails
    {
        return new DriverDetails($name, '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595');
    }
}
