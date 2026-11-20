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
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refueling;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Domain\Aggregate\Waybill\OdometerLog;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillTest extends TestCase
{
    private ?Vehicle $vehicle = null;

    public function test_valid_waybill_owns_its_local_data(): void
    {
        $sheet = $this->sheet();
        self::assertSame($sheet->vehicle()->getId(), $sheet->vehicleId());
        self::assertSame(30, $sheet->distance());
        self::assertSame(100030, $sheet->odometerEnd());
        self::assertEqualsWithDelta(3.0, $sheet->fuel()->consumed(), 0.000001);
        self::assertEqualsWithDelta(10.0, $sheet->actualConsumptionPer100Km(), 0.000001);
        self::assertEqualsWithDelta(3.0, $sheet->consumptionByNorm(), 0.000001);
        $readings = $sheet->routeOdometers();
        self::assertCount(2, $readings);
        self::assertSame(100000, $readings[0]->start);
        self::assertSame(100015, $readings[0]->end);
        self::assertSame(100015, $readings[1]->start);
        self::assertSame(100030, $readings[1]->end);
        self::assertFalse($sheet->isLocked());
    }

    public function test_route_distance_must_match_odometer_before_creating_sheet(): void
    {
        $this->expectException(AppException::class);
        $this->sheet(odometerEnd: 100031);
    }

    public function test_odometer_cannot_decrease(): void
    {
        $this->expectException(AppException::class);
        $this->sheet(odometerEnd: 99999);
    }

    public function test_draft_calculates_odometer_when_trips_change(): void
    {
        $draft = $this->draft();
        self::assertSame(100000, $draft->odometerEnd());
        $first = new RouteLog(Uuid::v7(), $this->vehicle(), null, new \DateTimeImmutable('2026-10-01'), new Destination(new Location('Первая точка'), 15), RouteLogOrigin::Manual);
        $second = new RouteLog(Uuid::v7(), $this->vehicle(), $first, new \DateTimeImmutable('2026-10-02'), new Destination(new Location('Вторая точка'), 200), RouteLogOrigin::Manual);

        $draft->setTrips($first);
        self::assertSame(100015, $draft->odometerEnd());
        $draft->setTrips($first, $second);
        self::assertSame(100215, $draft->odometerEnd());
        $draft->setTrips($first);
        self::assertSame(100015, $draft->odometerEnd());
        $draft->setTrips();
        self::assertSame(100000, $draft->odometerEnd());
    }

    public function test_draft_calculates_missing_odometer_and_rejects_both_mismatches_without_mutation(): void
    {
        $draft = $this->draft();
        $route = $this->route('2026-10-01');
        $draft->reviseDraft($draft->period(), null, null, $route);
        self::assertSame(100030, $draft->odometerEnd());

        foreach ([100029, 100031] as $invalid) {
            try {
                $draft->reviseDraft($draft->period(), $invalid, null, $route);
                self::fail('Несовпадающее показание нельзя сохранить.');
            } catch (AppException) {
                self::assertSame(100030, $draft->odometerEnd()); // @phpstan-ignore-line
                self::assertSame(30, $draft->route()->distance());
            }
            try {
                $draft->setOdometerEnd($invalid);
                self::fail('Несовпадающее показание нельзя установить.');
            } catch (AppException) {
                self::assertSame(100030, $draft->odometerEnd());
            }
        }
    }

    public function test_route_odometer_log_must_match_its_leg(): void
    {
        $log = iterator_to_array($this->route('2026-10-01')->logs(), false)[0];
        $this->expectException(AppException::class);
        new OdometerLog($log, 100, 116);
    }

    public function test_route_date_must_be_in_sheet_period(): void
    {
        $this->expectException(AppException::class);
        $this->sheet(route: $this->route('2026-09-30'));
    }

    public function test_refueling_date_must_be_in_sheet_period(): void
    {
        $fuel = new Fuel(10, 8, new Refuelings(null, $this->refueling('2026-09-30')), 'АИ-95');
        $this->expectException(AppException::class);
        $this->sheet(fuel: $fuel);
    }

    public function test_refueling_must_have_a_nearby_trip_in_current_sheet(): void
    {
        $fuel = new Fuel(10, 8, new Refuelings(null, $this->refueling('2026-10-04')), 'АИ-95');
        $this->expectException(AppException::class);
        $this->sheet(fuel: $fuel, route: $this->route('2026-10-01'));
    }

    public function test_nearby_trip_in_same_sheet_can_cover_refueling(): void
    {
        $fuel = new Fuel(10, 8, new Refuelings(null, $this->refueling('2026-10-02')), 'АИ-95');
        $sheet = $this->sheet(fuel: $fuel, route: $this->route('2026-10-01'));
        self::assertSame(30, $sheet->distance());
    }

    public function test_automatic_pair_cannot_have_unequal_distances(): void
    {
        $day = new \DateTimeImmutable('2026-10-01');
        $base = new Location('База');
        $destination = new Location('Точка А');
        $vehicle = $this->vehicle();
        $out = new RouteLog(Uuid::v7(), $vehicle, null, $day, new Destination($destination, 14), RouteLogOrigin::Automatic);
        $back = new RouteLog(Uuid::v7(), $vehicle, $out, $day, new Destination($base, 16), RouteLogOrigin::Automatic);
        $route = new Route($vehicle, $out, $back);

        $this->expectException(AppException::class);
        $this->sheet(route: $route);
    }

    public function test_trip_in_period_without_travel_is_rejected(): void
    {
        $this->expectException(AppException::class);
        $this->sheet(absence: $this->period('2026-10-01', '2026-10-03'));
    }

    public function test_absence_must_be_inside_sheet_period(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('внутри периода путевого листа');
        $this->sheet(absence: $this->period('2026-10-31', '2026-11-01'));
    }

    public function test_absences_cannot_overlap(): void
    {
        $vehicle = $this->vehicle();
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('не должны пересекаться');
        Waybill::create(
            Uuid::v7(), 1, $vehicle, $this->period('2026-10-01', '2026-10-31'), 100000,
            new Fuel(10, 10, new Refuelings(), 'АИ-95'), new Route($vehicle),
            $this->period('2026-10-02', '2026-10-03'), $this->period('2026-10-03', '2026-10-04'),
        );
    }

    public function test_empty_route_with_unchanged_odometer_can_be_locked(): void
    {
        $vehicle = $this->vehicle();
        $sheet = Waybill::create(
            Uuid::v7(), 1, $vehicle, $this->period('2026-10-01', '2026-10-31'), 100000,
            new Fuel(10, 10, new Refuelings(), 'АИ-95'), new Route($vehicle),
            $this->period('2026-10-02', '2026-10-03'),
        );

        $sheet->lock();

        self::assertTrue($sheet->isLocked());
        self::assertSame([], $sheet->trips());
        self::assertSame(0, $sheet->distance());
    }

    public function test_lock_prevents_further_mutations(): void
    {
        $sheet = $this->sheet();
        $sheet->lock();
        self::assertTrue($sheet->isLocked());

        $this->expectException(AppException::class);
        $sheet->revise($this->period('2026-10-01', '2026-10-31'), 100030, new Fuel(10, 7, new Refuelings(), 'АИ-95'), $this->route('2026-10-01'));
    }

    public function test_failed_revision_keeps_existing_state(): void
    {
        $sheet = $this->sheet();
        try {
            $sheet->revise($this->period('2026-10-01', '2026-10-31'), 100031, new Fuel(10, 7, new Refuelings(), 'АИ-95'), $this->route('2026-10-01'));
            self::fail('Пробег маршрута не совпадает с одометром.');
        } catch (AppException) {
            self::assertSame(100030, $sheet->odometerEnd());
            self::assertSame(30, $sheet->distance());
        }
    }

    public function test_consumption_warning_uses_vehicle_norm(): void
    {
        $within = $this->sheet(fuel: new Fuel(10, 6.55, new Refuelings(), 'АИ-95'));
        $over = $this->sheet(fuel: new Fuel(10, 6.54, new Refuelings(), 'АИ-95'));
        self::assertFalse($within->hasConsumptionDeviation());
        self::assertTrue($over->hasConsumptionDeviation());
    }

    public function test_sheet_fuel_type_must_be_one_of_vehicle_types(): void
    {
        $this->expectException(AppException::class);
        $this->sheet(fuel: new Fuel(10, 7, new Refuelings(), 'ДТ'));
    }

    public function test_route_for_another_vehicle_is_rejected_without_changing_the_sheet(): void
    {
        $sheet = $this->sheet();
        $vehicle = new Vehicle(Uuid::v7(), 'Другая модель', new RegistrationNumber('B124CC77'), new \DateTimeImmutable('2025-01-01'), new Location('База'), 0, BigDecimal::zero());
        $trip = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-10-01'), new Destination(new Location('Другая точка'), 30), RouteLogOrigin::Manual);
        try {
            $sheet->revise($sheet->period(), 100030, $sheet->fuel(), new Route($vehicle, $trip, $trip));
            self::fail('Маршрут другого автомобиля нельзя подставить в лист.');
        } catch (AppException) {
            self::assertNotSame($vehicle->getId(), $sheet->vehicleId());
            self::assertSame('АИ-95', $sheet->fuel()->type());
        }
    }

    private function sheet(?Fuel $fuel = null, ?Route $route = null, int $odometerEnd = 100030, ?WaybillPeriod $absence = null): Waybill
    {
        return Waybill::create(
            Uuid::v7(), 1, $this->vehicle(), $this->period('2026-10-01', '2026-10-31'), $odometerEnd,
            $fuel ?? new Fuel(10, 7, new Refuelings(), 'АИ-95'), $route ?? $this->route('2026-10-01'),
            ...($absence ? [$absence] : []),
        );
    }

    private function draft(): Waybill
    {
        return Waybill::createDraft(Uuid::v7(), 1, $this->vehicle(), new DateTimeInterval(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')));
    }

    private function period(string $from, string $to): WaybillPeriod
    {
        return WaybillPeriod::fromDates(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    private function route(string $day): Route
    {
        $base = new Location('База');
        $destination = new Location('Точка А');

        $vehicle = $this->vehicle();
        $first = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable($day), new Destination($destination, 15), RouteLogOrigin::Manual);
        $last = new RouteLog(Uuid::v7(), $vehicle, $first, new \DateTimeImmutable($day), new Destination($base, 15), RouteLogOrigin::Manual);

        return new Route($vehicle, $first, $last);
    }

    private function vehicle(): Vehicle
    {
        if (null === $this->vehicle) {
            $base = new Location('База');
            $vehicle = new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2025-01-01'), $base, 100000, BigDecimal::of(10), new PositiveNumber(10));
            $vehicle->enter(new DriverProfile(Uuid::v7(), 'owner', new DriverDetails('Иванов Иван', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), $base));
            $this->vehicle = $vehicle;
        }

        return $this->vehicle;
    }

    private function refueling(string $day): Refueling
    {
        return new Refueling(new \DateTimeImmutable($day), new PositiveNumber(1));
    }
}
