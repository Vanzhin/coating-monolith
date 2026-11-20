<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Vehicle;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class VehicleDriverTest extends TestCase
{
    public function test_driver_can_enter_a_free_vehicle_and_leave(): void
    {
        $vehicle = $this->vehicle();
        $driver = $this->driver($vehicle);

        self::assertNull($vehicle->driver());
        self::assertFalse($vehicle->isDrivenBy('owner'));
        $vehicle->enter($driver);
        self::assertSame($driver, $vehicle->driver());
        self::assertTrue($vehicle->isDrivenBy('owner'));
        self::assertFalse($vehicle->isDrivenBy('another'));
        $vehicle->leave($driver);
        self::assertNull($vehicle->driver());
    }

    public function test_occupied_vehicle_cannot_be_entered(): void
    {
        $vehicle = $this->vehicle();
        $driver = $this->driver($vehicle);
        $vehicle->enter($driver);

        try {
            $vehicle->enter($this->driver($vehicle));
            self::fail('Занятый автомобиль нельзя занять повторно.');
        } catch (AppException $exception) {
            self::assertStringContainsString('уже есть водитель', $exception->getMessage());
        }

        self::assertSame($driver, $vehicle->driver());
    }

    public function test_another_driver_cannot_leave_the_vehicle(): void
    {
        $vehicle = $this->vehicle();
        $driver = $this->driver($vehicle);
        $vehicle->enter($driver);

        try {
            $vehicle->leave($this->driver($vehicle));
            self::fail('Другой водитель не может освободить автомобиль.');
        } catch (AppException $exception) {
            self::assertStringContainsString('не находится', $exception->getMessage());
        }

        self::assertSame($driver, $vehicle->driver());
    }

    public function test_cannot_leave_a_free_vehicle(): void
    {
        $vehicle = $this->vehicle();

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('не находится');
        $vehicle->leave($this->driver($vehicle));
    }

    public function test_another_driver_can_enter_after_the_first_leaves(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->driver($vehicle);
        $second = $this->driver($vehicle);
        $vehicle->enter($first);
        $vehicle->leave($first);

        $vehicle->enter($second);

        self::assertSame($second, $vehicle->driver());
    }

    private function vehicle(): Vehicle
    {
        return new Vehicle(
            Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'),
            new \DateTimeImmutable('2025-01-01'), new Location('База'), 100, BigDecimal::of('30'),
        );
    }

    private function driver(Vehicle $vehicle): DriverProfile
    {
        return new DriverProfile(
            Uuid::v7(), 'owner',
            new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'),
            new Location('База'),
        );
    }
}
