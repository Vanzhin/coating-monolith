<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Waybill;

use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillDraftTest extends TestCase
{
    public function test_cannot_create_draft_when_vehicle_has_no_driver(): void
    {
        $vehicle = $this->vehicle();
        $driver = $this->driver($vehicle);
        $vehicle->leave($driver);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('без водителя автомобиля');
        Waybill::createDraft(Uuid::v7(), 1, $vehicle, $this->period());
    }

    public function test_new_draft_uses_vehicle_driver(): void
    {
        $vehicle = $this->vehicle();
        $driver = $this->driver($vehicle);
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, $this->period());

        self::assertSame($driver->getDriverDetails(), $draft->driver());
    }

    public function test_existing_draft_cannot_be_saved_without_a_driver(): void
    {
        $vehicle = $this->vehicle();
        $driver = $this->driver($vehicle);
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, $this->period());
        $vehicle->leave($driver);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('без водителя автомобиля');
        $draft->assertCanSave($vehicle);
    }

    public function test_existing_draft_can_be_saved_after_driver_changes(): void
    {
        $vehicle = $this->vehicle();
        $driver = $this->driver($vehicle);
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, $this->period());
        $vehicle->leave($driver);
        $newDriver = new DriverProfile(
            Uuid::v7(), 'other',
            new DriverDetails('Другой водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'),
            $vehicle->initialLocation(),
        );
        $vehicle->enter($newDriver);

        $draft->assertCanSave($vehicle);
        self::assertSame($vehicle->getId(), $draft->vehicleId());
    }

    public function test_draft_cannot_be_saved_with_another_vehicle(): void
    {
        $vehicle = $this->vehicle();
        $this->driver($vehicle);
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, $this->period());

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('другому автомобилю');
        $draft->assertCanSave($this->vehicle());
    }

    public function test_draft_copies_initial_vehicle_readings_without_final_data(): void
    {
        $vehicle = $this->vehicle('30.12345678901234567890');
        $driver = $this->driver($vehicle);
        $period = $this->period();
        $id = Uuid::v7();

        $draft = Waybill::createDraft($id, 17, $vehicle, $period);

        self::assertSame((string) $id, $draft->getId());
        self::assertSame(17, $draft->getNumber());
        self::assertSame($vehicle->getId(), $draft->vehicleId());
        self::assertTrue($draft->isForVehicle($vehicle));
        self::assertFalse($draft->isLocked());
        self::assertSame(100, $draft->odometerStart());
        self::assertSame('30.12345678901234567890', (string) $draft->fuelStart());
        self::assertSame($period->getFrom(), $draft->period()->from());
        self::assertSame($period->getTo(), $draft->period()->to());
    }

    public function test_draft_keeps_initial_readings_when_vehicle_changes(): void
    {
        $vehicle = $this->vehicle('30.12345678901234567890');
        $this->driver($vehicle);
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, $this->period());
        $trip = new RouteLog(
            Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-10 10:00:00+03:00'),
            new Destination(new Location('Б'), 3), RouteLogOrigin::Manual,
        );

        $vehicle->approveRoute(new Route($vehicle, $trip, $trip), null, BigDecimal::of('20'));

        self::assertSame(103, $vehicle->currentOdometer());
        self::assertSame('20', (string) $vehicle->fuelRemaining());
        self::assertSame(100, $draft->odometerStart());
        self::assertSame('30.12345678901234567890', (string) $draft->fuelStart());
    }

    public function test_cannot_lock_incomplete_draft(): void
    {
        $vehicle = $this->vehicle();
        $this->driver($vehicle);
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, $this->period());

        try {
            $draft->lock();
            self::fail('Неполный черновик нельзя утвердить.');
        } catch (AppException $exception) {
            self::assertStringContainsString('перед утверждением', $exception->getMessage());
        }

        self::assertFalse($draft->isLocked());
    }

    public function test_draft_can_save_fuel_without_a_route_and_calculates_odometer(): void
    {
        $vehicle = $this->vehicle();
        $this->driver($vehicle);
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, $this->period());
        $fuel = new Fuel(30, 25, new Refuelings(), 'АИ-95');

        $draft->reviseDraft($draft->period(), null, $fuel, null);

        self::assertSame($fuel, $draft->fuel());
        self::assertFalse($draft->hasRoute());
        self::assertSame(100, $draft->odometerEnd());
    }

    public function test_draft_can_save_refueling_before_final_fuel_remaining(): void
    {
        $vehicle = $this->vehicle();
        $this->driver($vehicle);
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, $this->period());
        $fuel = new Fuel(30, null, new Refuelings(new \App\Shared\Domain\Aggregate\ValueObject\PositiveNumber(12)), 'АИ-95');
        $draft->reviseDraft($draft->period(), null, $fuel, null);

        self::assertSame(12, $draft->fuel()->refuelings()->total());
        self::assertNull($draft->fuel()->remainingOrNull());
    }

    public function test_invalid_draft_revision_does_not_discard_previously_entered_fuel(): void
    {
        $vehicle = $this->vehicle();
        $this->driver($vehicle);
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, $this->period());
        $fuel = new Fuel(30, 25, new Refuelings(), 'АИ-95');
        $draft->reviseDraft($draft->period(), null, $fuel, null);

        try {
            $draft->reviseDraft($draft->period(), 99, null, null);
            self::fail('Конечное показание меньше начального.');
        } catch (AppException) {
            self::assertSame($fuel, $draft->fuel());
            self::assertFalse($draft->hasRoute());
        }
    }

    public function test_draft_rejects_fractional_final_odometer(): void
    {
        $vehicle = $this->vehicle();
        $this->driver($vehicle);
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, $this->period());

        $this->expectException(\TypeError::class);
        $draft->setOdometerEnd(101.5); // @phpstan-ignore-line
    }

    public function test_new_draft_starts_with_the_initial_odometer(): void
    {
        $vehicle = $this->vehicle();
        $this->driver($vehicle);
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, $this->period());

        self::assertSame(100, $draft->odometerEnd());
    }

    public function test_draft_requires_start_of_period(): void
    {
        $vehicle = $this->vehicle();

        $this->driver($vehicle);
        $this->expectException(AppException::class);
        Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(to: new \DateTimeImmutable('2026-01-31')));
    }

    public function test_draft_requires_end_of_period(): void
    {
        $vehicle = $this->vehicle();

        $this->driver($vehicle);
        $this->expectException(AppException::class);
        Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(from: new \DateTimeImmutable('2026-01-01')));
    }

    public function test_draft_period_preserves_instant_across_time_zones(): void
    {
        $vehicle = $this->vehicle();
        $from = new \DateTimeImmutable('2026-02-01 00:30:00+03:00');
        $to = new \DateTimeImmutable('2026-01-31 22:00:00+00:00');

        $this->driver($vehicle);
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval($from, $to));

        self::assertSame($from, $draft->period()->from());
        self::assertSame($to, $draft->period()->to());
    }

    public function test_draft_can_be_completed_and_locked(): void
    {
        $vehicle = $this->vehicle();
        $this->driver($vehicle);
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, $this->period());
        $trip = new RouteLog(
            Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-10 10:00:00+03:00'),
            new Destination(new Location('Б'), 3), RouteLogOrigin::Manual,
        );

        $draft->revise($draft->period(), 103, new Fuel(30, 25, new Refuelings(), 'АИ-95'), new Route($vehicle, $trip, $trip));
        $draft->lock();

        self::assertTrue($draft->isLocked());
        self::assertSame(100, $draft->odometerStart());
        self::assertSame('30', (string) $draft->fuelStart());
        self::assertSame(103, $draft->odometerEnd());
    }

    private function vehicle(string $fuel = '30'): Vehicle
    {
        return new Vehicle(
            Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'),
            new \DateTimeImmutable('2025-01-01 00:00:00+00:00'), new Location('А'), 100, BigDecimal::of($fuel),
        );
    }

    private function driver(Vehicle $vehicle): DriverProfile
    {
        $driver = new DriverProfile(
            Uuid::v7(),
            'owner',
            new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'),
            $vehicle->initialLocation(),
        );
        $vehicle->enter($driver);

        return $driver;
    }

    private function period(): DateTimeInterval
    {
        return new DateTimeInterval(
            new \DateTimeImmutable('2026-01-01 08:15:30.123456+03:00'),
            new \DateTimeImmutable('2026-01-31 18:45:00.654321+03:00'),
        );
    }
}
