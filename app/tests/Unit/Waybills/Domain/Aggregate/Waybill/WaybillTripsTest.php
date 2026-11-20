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

final class WaybillTripsTest extends TestCase
{
    public function test_draft_calculates_odometer_from_trips_without_fuel(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $before = $this->trip($vehicle, null, '2025-12-31 10:00:00+03:00', 'Б');
        $first = $this->trip($vehicle, $before, '2026-01-10 10:00:00+03:00', 'В', 2);
        $last = $this->trip($vehicle, $first, '2026-01-10 12:00:00+03:00', 'Г', 3);

        $draft->setTrips($first, $last);

        self::assertSame([$first, $last], iterator_to_array($draft->route()->logs(), false));
        self::assertSame($before, $draft->route()->first()?->previous);
        self::assertSame(5, $draft->route()->distance());
        self::assertFalse($draft->isLocked());
        self::assertSame(105, $draft->odometerEnd());
        try {
            $draft->fuel();
            self::fail('Топливо не было введено.');
        } catch (AppException $exception) {
            self::assertStringContainsString('топливе', $exception->getMessage());
        }

        $draft->setOdometerEnd(105);
        self::assertSame(105, $draft->odometerEnd());
        self::assertSame([$first, $last], iterator_to_array($draft->route()->logs(), false));
    }

    public function test_invalid_trip_replacement_preserves_previous_trips(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $first = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б');
        $draft->setTrips($first);
        $outsidePeriod = $this->trip($vehicle, $first, '2026-02-01 10:00:00+03:00', 'В');

        try {
            $draft->setTrips($first, $outsidePeriod);
            self::fail('Поездка за пределами периода должна быть отклонена.');
        } catch (AppException $exception) {
            self::assertStringContainsString('вне периода', $exception->getMessage());
        }
        self::assertSame([$first], iterator_to_array($draft->route()->logs(), false));
    }

    public function test_replacing_a_trip_cannot_silently_change_the_next_departure(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $first = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б');
        $next = $this->trip($vehicle, $first, '2026-01-10 12:00:00+03:00', 'В');
        $draft->setTrips($first, $next);
        $replacement = new RouteLog($first->id, $vehicle, null, $first->date, new Destination(new Location('Г'), 1), RouteLogOrigin::Manual);
        $unchangedNext = $next->withPrevious($replacement);

        try {
            $draft->setTrips($replacement, $unchangedNext);
            self::fail('Отправление следующей поездки нельзя менять неявно.');
        } catch (AppException $exception) {
            self::assertStringContainsString('следующую поездку', $exception->getMessage());
        }
        self::assertSame([$first, $next], $draft->trips());
    }

    public function test_removing_a_trip_relinks_the_next_trip(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $first = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б');
        $removed = $this->trip($vehicle, $first, '2026-01-11 10:00:00+03:00', 'В');
        $last = $this->trip($vehicle, $removed, '2026-01-12 10:00:00+03:00', 'Г');
        $draft->setTrips($first, $removed, $last);

        $relinkedLast = $last->withPrevious($first);
        $draft->setTrips($first, $relinkedLast);

        self::assertSame([$first, $relinkedLast], $draft->trips());
        self::assertSame($first, $relinkedLast->previous);
        self::assertSame($first->destination->location, $relinkedLast->departure());
        self::assertSame(102, $draft->odometerEnd());
    }

    public function test_inserting_a_trip_relinks_the_next_trip(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $first = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б');
        $last = $this->trip($vehicle, $first, '2026-01-12 10:00:00+03:00', 'Г');
        $draft->setTrips($first, $last);

        $inserted = $this->trip($vehicle, $first, '2026-01-11 10:00:00+03:00', 'В');
        $relinkedLast = $last->withPrevious($inserted);
        $draft->setTrips($first, $inserted, $relinkedLast);

        self::assertSame([$first, $inserted, $relinkedLast], $draft->trips());
        self::assertSame($inserted, $relinkedLast->previous);
        self::assertSame($inserted->destination->location, $relinkedLast->departure());
        self::assertSame(103, $draft->odometerEnd());
    }

    public function test_trip_recalculates_odometer_instead_of_retaining_an_old_value(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $trip = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б', 2);
        $draft->setTrips($trip);

        self::assertSame(102, $draft->odometerEnd());
        self::assertSame([$trip], $draft->trips());
    }

    public function test_incomplete_draft_rejects_trip_that_would_overflow_odometer(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $trip = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б', PHP_INT_MAX);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('одометра слишком велико');
        $draft->setTrips($trip);
    }

    public function test_checks_linked_trips_without_a_route_object(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $before = $this->trip($vehicle, null, '2025-12-31 10:00:00+03:00', 'Б');
        $first = $this->trip($vehicle, $before, '2026-01-10 10:00:00+03:00', 'В', 2);
        $second = $this->trip($vehicle, $first, '2026-01-10 12:00:00+03:00', 'Г', 2);

        $draft->assertTrips(104, $first, $second);

        self::assertFalse($draft->isLocked());
        self::assertSame(100, $draft->odometerStart());
        self::assertSame(100, $vehicle->currentOdometer());
    }

    public function test_waybill_keeps_shared_destination_enterprise_distance_and_chain(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $destination = new Destination(new Location('Б'), 2, '  Склад  ');
        $driver = new DriverProfile(
            Uuid::v7(), 'owner',
            new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'),
            $vehicle->initialLocation(), $destination,
        );
        $first = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-10 10:00:00+03:00'), $driver->getDestinations()[0], RouteLogOrigin::Manual);
        $second = $this->trip($vehicle, $first, '2026-01-10 12:00:00+03:00', 'В', 2);
        $route = new Route($vehicle, $first, $second);

        $draft->revise($draft->period(), 104, new Fuel(30, 25, new Refuelings(), 'АИ-95'), $route);
        $logs = iterator_to_array($draft->route()->logs(), false);

        self::assertSame($destination, $logs[0]->destination);
        self::assertSame('Склад', $logs[0]->destination->enterprise);
        self::assertSame(2, $logs[0]->distanceInKilometers());
        self::assertSame($destination->location, $logs[1]->departure());
        self::assertSame($first, $logs[1]->previous);
        self::assertSame(4, $draft->route()->distance());
    }

    public function test_checks_predecessor_by_id_not_object_instance(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $first = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б');
        $sameFirst = clone $first;
        $second = $this->trip($vehicle, $sameFirst, '2026-01-10 12:00:00+03:00', 'В');

        $draft->assertTrips(102, $first, $second);

        self::assertNotSame($first, $sameFirst);
        self::assertTrue($second->follows($first));
    }

    public function test_rejects_unlinked_trips(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $first = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б');
        $other = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'В');
        $second = $this->trip($vehicle, $other, '2026-01-10 12:00:00+03:00', 'Г');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('последовательную цепочку');
        $draft->assertTrips(102, $first, $second);
    }

    public function test_rejects_the_same_trip_twice(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $trip = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('дважды');
        $draft->assertTrips(102, $trip, clone $trip);
    }

    public function test_rejects_trip_from_another_vehicle(): void
    {
        $draft = $this->draft($this->vehicle());
        $trip = $this->trip($this->vehicle(), null, '2026-01-10 10:00:00+03:00', 'Б');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('другому автомобилю');
        $draft->assertTrips(101, $trip);
    }

    public function test_rejects_trip_before_period_start_on_the_same_day(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $trip = $this->trip($vehicle, null, '2026-01-01 07:59:59.999999+03:00', 'Б');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('вне периода');
        $draft->assertTrips(101, $trip);
    }

    public function test_rejects_trip_after_period_end_on_the_same_day(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $trip = $this->trip($vehicle, null, '2026-01-31 18:00:00.000001+03:00', 'Б');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('вне периода');
        $draft->assertTrips(101, $trip);
    }

    public function test_accepts_trips_at_both_period_boundaries(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $first = $this->trip($vehicle, null, '2026-01-01 08:00:00+03:00', 'Б');
        $second = $this->trip($vehicle, $first, '2026-01-31 18:00:00+03:00', 'В');

        $draft->assertTrips(102, $first, $second);

        self::assertFalse($draft->isLocked());
    }

    public function test_rejects_partial_trip_distance_before_automatic_planning(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $trip = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б', 2);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('должен совпадать');
        $draft->assertTrips(110, $trip);
    }

    public function test_draft_can_set_target_odometer_after_trips(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $trip = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б', 2);

        $draft->setTrips($trip);
        $draft->setOdometerEnd(102);

        self::assertSame(102, $draft->odometerEnd());
        self::assertSame(100, $draft->odometerStart());
        self::assertSame(100, $vehicle->currentOdometer());
        self::assertFalse($draft->isLocked());
    }

    public function test_rejects_invalid_target_without_changing_previously_saved_target(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $trip = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б', 2);
        $draft->setTrips($trip);
        $draft->setOdometerEnd(102);

        try {
            $draft->setOdometerEnd(101);
            self::fail('Пробег поездок не должен превышать целевое показание.');
        } catch (AppException $exception) {
            self::assertStringContainsString('не может превышать', $exception->getMessage());
        }

        self::assertSame(102, $draft->odometerEnd());
        self::assertSame(100, $vehicle->currentOdometer());
    }

    public function test_target_alone_does_not_make_draft_ready_for_approval(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $draft->setOdometerEnd(100);

        try {
            $draft->lock();
            self::fail('Целевого показания недостаточно для утверждения.');
        } catch (AppException $exception) {
            self::assertStringContainsString('перед утверждением', $exception->getMessage());
        }

        self::assertFalse($draft->isLocked());
        self::assertSame(100, $draft->odometerEnd());
    }

    public function test_complete_trips_can_be_checked_without_a_route_object(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $first = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б', 2);
        $second = $this->trip($vehicle, $first, '2026-01-10 12:00:00+03:00', 'В', 2);
        $draft->setTrips($first, $second);
        $draft->setOdometerEnd(104);

        $draft->assertTripsComplete($first, $second);

        self::assertSame(104, $draft->odometerEnd());
        self::assertFalse($draft->isLocked());
        self::assertSame(100, $vehicle->currentOdometer());
    }

    public function test_partial_trips_are_not_valid_against_the_calculated_odometer(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $trip = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б', 2);
        $draft->setTrips($trip);
        $second = $this->trip($vehicle, $trip, '2026-01-11 10:00:00+03:00', 'В', 8);
        $draft->setTrips($trip, $second);

        try {
            $draft->assertTripsComplete($trip);
            self::fail('Неполный пробег нельзя считать завершённым маршрутом.');
        } catch (AppException $exception) {
            self::assertStringContainsString('должен совпадать', $exception->getMessage());
        }

        self::assertFalse($draft->isLocked());
        self::assertSame(110, $draft->odometerEnd());
    }

    public function test_empty_trips_match_the_initial_odometer(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);

        $draft->assertTripsComplete();
        self::assertSame(100, $draft->odometerEnd());
    }

    public function test_rejects_target_change_when_it_conflicts_with_existing_route(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $trip = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б', 2);
        $route = new Route($vehicle, $trip, $trip);
        $draft->revise($draft->period(), 102, new Fuel(30, 25, new Refuelings(), 'АИ-95'), $route);

        try {
            $draft->setOdometerEnd(101);
            self::fail('Существующий маршрут нельзя оставить несогласованным.');
        } catch (AppException $exception) {
            self::assertStringContainsString('не может превышать', $exception->getMessage());
        }

        self::assertSame(102, $draft->odometerEnd());
        self::assertSame($route, $draft->route());
    }

    public function test_rejects_target_change_after_approval(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $trip = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б', 2);
        $draft->revise($draft->period(), 102, new Fuel(30, 25, new Refuelings(), 'АИ-95'), new Route($vehicle, $trip, $trip));
        $draft->lock();

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Зафиксированный');
        $draft->setOdometerEnd(110);
    }

    public function test_rejects_trip_distance_exceeding_odometer_difference(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $trip = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б', 2);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('не может превышать');
        $draft->assertTrips(101, $trip);
    }

    public function test_accepts_an_automatic_round_trip_from_base(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $out = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б', 5, RouteLogOrigin::Automatic);
        $back = $this->trip($vehicle, $out, '2026-01-10 12:00:00+03:00', 'А', 5, RouteLogOrigin::Automatic);

        $draft->assertTrips(110, $out, $back);

        self::assertFalse($draft->isLocked());
    }

    public function test_rejects_automatic_trip_without_return(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $out = $this->trip($vehicle, null, '2026-01-10 10:00:00+03:00', 'Б', 5, RouteLogOrigin::Automatic);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('не образуют целую пару');
        $draft->assertTrips(105, $out);
    }

    private function vehicle(): Vehicle
    {
        return new Vehicle(
            Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'),
            new \DateTimeImmutable('2025-01-01 00:00:00+00:00'), new Location('А'), 100, BigDecimal::of('30'),
        );
    }

    private function draft(Vehicle $vehicle): Waybill
    {
        $driver = new DriverProfile(
            Uuid::v7(), 'owner',
            new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'),
            $vehicle->initialLocation(),
        );
        $vehicle->enter($driver);

        return Waybill::createDraft(
            Uuid::v7(), 1, $vehicle,
            new DateTimeInterval(new \DateTimeImmutable('2026-01-01 08:00:00+03:00'), new \DateTimeImmutable('2026-01-31 18:00:00+03:00')),
        );
    }

    private function trip(
        Vehicle $vehicle,
        ?RouteLog $previous,
        string $date,
        string $destination,
        int $distance = 1,
        RouteLogOrigin $origin = RouteLogOrigin::Manual,
    ): RouteLog {
        return new RouteLog(Uuid::v7(), $vehicle, $previous, new \DateTimeImmutable($date), new Destination(new Location($destination), $distance), $origin);
    }
}
