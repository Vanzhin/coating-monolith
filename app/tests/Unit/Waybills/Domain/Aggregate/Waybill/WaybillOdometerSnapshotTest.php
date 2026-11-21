<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Waybill;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
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
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillOdometerSnapshotTest extends TestCase
{
    public function test_approved_sheet_keeps_its_readings_after_the_vehicle_moves_again(): void
    {
        $vehicle = $this->vehicle();
        [$october, $lastOctober] = $this->roundTrip($vehicle, null, '2026-10-10', 20, 30);
        $sheet = $this->sheet($vehicle, $october, '2026-10-01', '2026-10-31', 100050);

        self::assertSame(100000, $sheet->odometerStart());
        self::assertSame('30', (string) $sheet->fuelStart());
        $sheet->lock();
        $vehicle->approveRoute($october, null, BigDecimal::of('25'));
        [$november] = $this->roundTrip($vehicle, $lastOctober, '2026-11-10', 10, 10);
        $vehicle->approveRoute($november, $lastOctober, BigDecimal::of('20'));
        $vehicle->rename('Другая модель');

        self::assertSame(100070, $vehicle->currentOdometer());
        self::assertSame(100000, $sheet->odometerStart());
        self::assertSame('30', (string) $sheet->fuelStart());
        self::assertSame('20', (string) $vehicle->fuelRemaining());
        self::assertSame(100050, $sheet->odometerEnd());
        self::assertSame('Модель', $sheet->vehicleModel());
        self::assertSame('Другая модель', $sheet->vehicle()->getModel());
        $odometers = $sheet->routeOdometers();
        self::assertSame(100000, $odometers[0]->start);
        self::assertSame(100020, $odometers[0]->end);
        self::assertSame(100020, $odometers[1]->start);
        self::assertSame(100050, $odometers[1]->end);
    }

    public function test_new_sheet_takes_the_current_reading_of_the_vehicle(): void
    {
        $vehicle = $this->vehicle();
        [$october, $lastOctober] = $this->roundTrip($vehicle, null, '2026-10-10', 20, 30);
        $vehicle->approveRoute($october, null, BigDecimal::of('25'));
        [$november] = $this->roundTrip($vehicle, $lastOctober, '2026-11-10', 10, 10);

        $sheet = $this->sheet($vehicle, $november, '2026-11-01', '2026-11-30', 100070, new Fuel(25, 25, new Refuelings(), 'АИ-95'));

        self::assertSame(100050, $sheet->odometerStart());
        self::assertSame('25', (string) $sheet->fuelStart());
        self::assertSame(100070, $sheet->odometerEnd());
        self::assertSame(20, $sheet->distance());
    }

    public function test_restoring_a_sheet_uses_the_saved_reading_not_the_current_vehicle(): void
    {
        $vehicle = $this->vehicle();
        [$route] = $this->roundTrip($vehicle, null, '2026-10-10', 20, 30);
        $vehicle->approveRoute($route, null, BigDecimal::of('25'));
        $restored = Waybill::restore(
            Uuid::v7(), 1, $vehicle, 100000, BigDecimal::of('30'),
            $this->period('2026-10-01', '2026-10-31'), 100050, $this->fuel(), $route,
        );

        self::assertSame(100050, $vehicle->currentOdometer());
        self::assertSame(100000, $restored->odometerStart());
        self::assertSame('30', (string) $restored->fuelStart());
        self::assertSame(100050, $restored->odometerEnd());
    }

    public function test_revision_keeps_initial_reading_and_rejects_inconsistent_distance(): void
    {
        $vehicle = $this->vehicle();
        [$route] = $this->roundTrip($vehicle, null, '2026-10-10', 20, 30);
        $sheet = $this->sheet($vehicle, $route, '2026-10-01', '2026-10-31', 100050);

        try {
            $sheet->revise($this->period('2026-10-01', '2026-10-31'), 100051, $this->fuel(), $route);
            self::fail('Пробег должен соответствовать маршруту.');
        } catch (AppException $exception) {
            self::assertStringContainsString('Пробег маршрута', $exception->getMessage());
        }

        self::assertSame(100000, $sheet->odometerStart());
        self::assertSame(100050, $sheet->odometerEnd());
    }

    public function test_approved_sheet_rejects_revision(): void
    {
        $vehicle = $this->vehicle();
        [$route] = $this->roundTrip($vehicle, null, '2026-10-10', 20, 30);
        $sheet = $this->sheet($vehicle, $route, '2026-10-01', '2026-10-31', 100050);
        $sheet->lock();

        $this->expectException(AppException::class);
        $sheet->revise($this->period('2026-10-01', '2026-10-31'), 100050, $this->fuel(), $route);
    }

    public function test_other_vehicle_cannot_supply_the_route(): void
    {
        $vehicle = $this->vehicle();
        $other = $this->vehicle();
        [$route] = $this->roundTrip($other, null, '2026-10-10', 20, 30);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('другому автомобилю');
        $this->sheet($vehicle, $route, '2026-10-01', '2026-10-31', 100050);
    }

    public function test_integer_distances_are_accumulated_in_the_odometer_rows(): void
    {
        $vehicle = $this->vehicle();
        [$route] = $this->roundTrip($vehicle, null, '2026-10-10', 1, 2);
        $sheet = $this->sheet($vehicle, $route, '2026-10-01', '2026-10-31', 100003);
        $odometers = $sheet->routeOdometers();

        self::assertSame(100001, $odometers[0]->end);
        self::assertSame(100003, $odometers[1]->end);
        self::assertSame(3, $sheet->distance());
    }

    public function test_updating_vehicle_details_does_not_change_the_initial_reading(): void
    {
        $vehicle = $this->vehicle();
        [$route] = $this->roundTrip($vehicle, null, '2026-10-10', 20, 30);
        $sheet = $this->sheet($vehicle, $route, '2026-10-01', '2026-10-31', 100050);
        $vehicle->approveRoute($route, null, BigDecimal::of('25'));

        $vehicle->rename('Другая модель');
        self::assertSame(100000, $sheet->odometerStart());
    }

    public function test_restored_sheet_rejects_negative_initial_reading(): void
    {
        $vehicle = $this->vehicle();
        [$route] = $this->roundTrip($vehicle, null, '2026-10-10', 20, 30);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Начальное показание одометра');

        Waybill::restore(
            Uuid::v7(), 1, $vehicle,
            -1, BigDecimal::of('30'), $this->period('2026-10-01', '2026-10-31'), 49, $this->fuel(), $route,
        );
    }

    public function test_new_sheet_rejects_fuel_start_different_from_vehicle(): void
    {
        $vehicle = $this->vehicle();
        [$route] = $this->roundTrip($vehicle, null, '2026-10-10', 20, 30);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Начальный остаток топлива');
        $this->sheet($vehicle, $route, '2026-10-01', '2026-10-31', 100050, new Fuel(29, 25, new Refuelings(), 'АИ-95'));
    }

    public function test_revision_cannot_replace_initial_fuel_reading(): void
    {
        $vehicle = $this->vehicle();
        [$route] = $this->roundTrip($vehicle, null, '2026-10-10', 20, 30);
        $sheet = $this->sheet($vehicle, $route, '2026-10-01', '2026-10-31', 100050);

        try {
            $sheet->revise(
                $this->period('2026-10-01', '2026-10-31'), 100050,
                new Fuel(29, 25, new Refuelings(), 'АИ-95'), $route,
            );
            self::fail('Начальный остаток топлива не должен измениться.');
        } catch (AppException $exception) {
            self::assertStringContainsString('Начальный остаток топлива', $exception->getMessage());
        }

        self::assertSame('30', (string) $sheet->fuelStart());
        self::assertSame(30, $sheet->fuel()->initial());
    }

    public function test_sheet_allows_positive_fuel_reconciliation(): void
    {
        $vehicle = $this->vehicle();
        [$route] = $this->roundTrip($vehicle, null, '2026-10-10', 20, 30);
        $fuel = new Fuel(30, 55, new Refuelings(new PositiveNumber(20)), 'АИ-95');

        $sheet = $this->sheet($vehicle, $route, '2026-10-01', '2026-10-31', 100050, $fuel);

        self::assertSame('30', (string) $sheet->fuelStart());
        self::assertSame(-5, $sheet->fuel()->consumed());
    }

    public function test_restored_sheet_rejects_negative_initial_fuel(): void
    {
        $vehicle = $this->vehicle();
        [$route] = $this->roundTrip($vehicle, null, '2026-10-10', 20, 30);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Начальный остаток топлива');
        Waybill::restore(
            Uuid::v7(), 1, $vehicle,
            100000, BigDecimal::of('-1'), $this->period('2026-10-01', '2026-10-31'), 100050, $this->fuel(), $route,
        );
    }

    private function vehicle(): Vehicle
    {
        $vehicle = new Vehicle(
            Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'),
            new \DateTimeImmutable('2025-01-01 00:00:00+00:00'), new Location('Россия', 'Москва', 'Лесная', '1'), 100000, BigDecimal::of('30'),
        );
        $vehicle->enter(new DriverProfile(Uuid::v7(), 'owner', $this->driver(), $vehicle->initialLocation()));

        return $vehicle;
    }

    /** @return array{Route, RouteLog} */
    private function roundTrip(Vehicle $vehicle, ?RouteLog $previous, string $date, int $outward, int $back): array
    {
        $base = $vehicle->initialLocation();
        $destination = new Location('Россия', 'Москва', 'Складская', '2');
        $day = new \DateTimeImmutable($date);
        $first = new RouteLog(Uuid::v7(), $vehicle, $previous, $day, new Destination($destination, $outward), RouteLogOrigin::Manual);
        $last = new RouteLog(Uuid::v7(), $vehicle, $first, $day, new Destination($base, $back), RouteLogOrigin::Manual);

        return [new Route($vehicle, $first, $last), $last];
    }

    private function sheet(Vehicle $vehicle, Route $route, string $from, string $to, int $end, ?Fuel $fuel = null): Waybill
    {
        return Waybill::create(
            Uuid::v7(), 1, $vehicle,
            $this->period($from, $to), $end, $fuel ?? $this->fuel(), $route,
        );
    }

    private function driver(): DriverDetails
    {
        return new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595');
    }

    private function fuel(): Fuel
    {
        return new Fuel(30, 25, new Refuelings(), 'АИ-95');
    }

    private function period(string $from, string $to): WaybillPeriod
    {
        return WaybillPeriod::fromDates(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }
}
