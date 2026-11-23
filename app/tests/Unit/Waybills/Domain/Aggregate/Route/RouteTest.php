<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Route;

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
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RouteTest extends TestCase
{
    private ?Vehicle $linkedVehicle = null;

    public function test_keeps_explicit_order_and_allows_return_on_another_day(): void
    {
        $first = $this->linked(null, '2026-01-01 23:00', 'Б');
        $second = $this->linked($first, '2026-01-01 23:30', 'В');
        $third = $this->linked($second, '2026-01-02', 'А');
        $route = new Route($this->vehicle(), $first, $third);

        self::assertSame([$first, $second, $third], iterator_to_array($route->logs(), false));
    }

    public function test_date_order_uses_instants_across_time_zones(): void
    {
        $first = $this->linked(null, '2026-01-01 23:00 -12:00', 'Б');

        $this->expectException(AppException::class);
        $this->linked($first, '2026-01-02 01:00 +14:00', 'В');
    }

    public function test_return_on_same_day_is_also_allowed(): void
    {
        $outward = $this->linked(null, '2026-01-01', 'Б');
        $return = $this->linked($outward, '2026-01-01', 'А');

        self::assertSame([$outward, $return], iterator_to_array((new Route($this->vehicle(), $outward, $return))->logs(), false));
    }

    public function test_rejects_discontinuous_route(): void
    {
        $this->expectException(AppException::class);
        $first = $this->linked(null, '2026-01-01', 'Б');
        $unrelated = $this->linked(null, '2026-01-02', 'Г');
        Route::fromUnorderedTrips($this->vehicle(), $first, $unrelated);
    }

    public function test_later_instant_is_allowed_even_if_local_date_is_earlier(): void
    {
        $first = $this->linked(null, '2026-01-02 00:00 +14:00', 'Б');
        $second = $this->linked($first, '2026-01-01 23:00 -12:00', 'В');

        self::assertSame([$first, $second], iterator_to_array((new Route($this->vehicle(), $first, $second))->logs(), false));
    }

    public function test_append_is_immutable_and_checks_continuity(): void
    {
        $first = $this->linked(null, '2026-01-01', 'Б');
        $second = $this->linked($first, '2026-01-02', 'В');
        $route = new Route($this->vehicle(), $first, $first);

        self::assertSame([$first, $second], iterator_to_array($route->insertAfter($first, $second)->logs(), false));
        try {
            $route->insertAfter($first, $this->linked(null, '2026-01-02', 'Г'));
            self::fail('Ожидалась ошибка непрерывности.');
        } catch (AppException) {
            self::assertSame([$first], iterator_to_array($route->logs(), false));
        }
    }

    public function test_replacement_validates_both_neighbours_atomically(): void
    {
        $first = $this->linked(null, '2026-01-01', 'Б');
        $middle = $this->linked($first, '2026-01-02', 'В');
        $last = $this->linked($middle, '2026-01-03', 'Г');
        $route = new Route($this->vehicle(), $first, $last);
        $rewritten = iterator_to_array($route->replaceSegment($middle, $middle, $middle)->logs(), false);
        self::assertSame([$first, $middle], array_slice($rewritten, 0, 2));
        self::assertSame((string) $last->id, (string) $rewritten[2]->id);
        try {
            $route->replaceSegment($middle, $middle, $this->linked($first, '2026-01-02', 'Д'));
            self::fail('Ожидалась ошибка непрерывности.');
        } catch (AppException) {
            self::assertSame([$first, $middle, $last], iterator_to_array($route->logs(), false));
        }
    }

    public function test_automatic_log_cannot_replace_manual_log(): void
    {
        $manual = $this->linked(null, '2026-01-01', 'Б');
        $route = new Route($this->vehicle(), $manual, $manual);
        $automatic = $this->linked(null, '2026-01-01', 'Б', origin: RouteLogOrigin::Automatic);

        try {
            $route->replaceSegment($manual, $manual, $automatic);
            self::fail('Ожидался запрет автоматической замены.');
        } catch (AppException) {
            self::assertSame([$manual], iterator_to_array($route->logs(), false));
        }
    }

    public function test_automatic_log_can_replace_automatic_log(): void
    {
        $first = $this->linked(null, '2026-01-01', 'Б', origin: RouteLogOrigin::Automatic);
        $replacement = $this->linked(null, '2026-01-02', 'В', origin: RouteLogOrigin::Automatic);
        $route = new Route($this->vehicle(), $first, $first);

        self::assertSame([$replacement], iterator_to_array($route->replaceSegment($first, $first, $replacement)->logs(), false));
        self::assertSame([$first], iterator_to_array($route->logs(), false));
    }

    public function test_editing_legacy_automatic_pair_requires_explicit_manual_replacement(): void
    {
        $outward = $this->linked(null, '2026-01-01', 'Б', 14, RouteLogOrigin::Automatic);
        $return = $this->linked($outward, '2026-01-01', 'А', 14, RouteLogOrigin::Automatic);
        $route = new Route($this->vehicle(), $outward, $return);
        $edited = new RouteLog($outward->id, $this->vehicle(), null, $outward->date, new Destination(new Location('Б'), 16), RouteLogOrigin::Manual);
        $editedReturn = new RouteLog($return->id, $this->vehicle(), $edited, $return->date, new Destination(new Location('А'), 16), RouteLogOrigin::Manual);

        $updated = iterator_to_array($route->replaceSegment($outward, $return, $edited, $editedReturn)->logs(), false);

        self::assertSame([$edited, $editedReturn], $updated);
        self::assertSame([RouteLogOrigin::Manual, RouteLogOrigin::Manual], array_map(static fn (RouteLog $log): RouteLogOrigin => $log->origin, $updated));
        self::assertSame(16, $updated[1]->distanceInKilometers());
        self::assertSame([$outward, $return], iterator_to_array($route->logs(), false));
    }

    public function test_replacement_cannot_break_date_order(): void
    {
        $first = $this->linked(null, '2026-01-01', 'Б');
        $last = $this->linked($first, '2026-01-02', 'В');
        $route = new Route($this->vehicle(), $first, $last);

        try {
            $route->replaceSegment($first, $first, $this->linked(null, '2026-01-03', 'Б'));
            self::fail('Ожидалась ошибка порядка дат.');
        } catch (AppException) {
            self::assertSame([$first, $last], iterator_to_array($route->logs(), false));
        }
    }

    public function test_returned_list_cannot_mutate_route(): void
    {
        $first = $this->linked(null, '2026-01-01', 'Б');
        $route = new Route($this->vehicle(), $first, $first);
        $logs = iterator_to_array($route->logs(), false);
        $logs[] = $this->linked($first, '2026-01-01', 'Г');

        self::assertSame([$first], iterator_to_array($route->logs(), false));
    }

    public function test_removing_middle_log_cannot_break_continuity(): void
    {
        $first = $this->linked(null, '2026-01-01', 'Б');
        $middle = $this->linked($first, '2026-01-02', 'В');
        $last = $this->linked($middle, '2026-01-03', 'Г');
        $route = new Route($this->vehicle(), $first, $last);

        self::assertSame([$first, $middle], iterator_to_array($route->replaceSegment($last, $last)->logs(), false));
        try {
            $route->replaceSegment($middle, $middle);
            self::fail('Ожидалась ошибка непрерывности.');
        } catch (AppException) {
            self::assertSame([$first, $middle, $last], iterator_to_array($route->logs(), false));
        }
    }

    public function test_rejects_a_segment_outside_the_route(): void
    {
        $outside = $this->linked(null, '2026-01-01', 'Б');
        $this->expectException(AppException::class);
        (new Route($this->vehicle()))->replaceSegment($outside, $outside);
    }

    public function test_calculates_total_distance(): void
    {
        $first = $this->linked(null, '2026-01-01', 'Б');
        $last = $this->linked($first, '2026-01-01', 'А');
        $route = new Route($this->vehicle(), $first, $last);
        self::assertSame(2, $route->distance());
        self::assertSame(0, (new Route($this->vehicle()))->distance());
    }

    public function test_rejects_overflow_of_total_distance(): void
    {
        $first = $this->linked(null, '2026-01-01', 'Б', PHP_INT_MAX);
        $last = $this->linked($first, '2026-01-01', 'А');
        $this->expectException(AppException::class);
        new Route($this->vehicle(), $first, $last);
    }

    public function test_waybill_period_requires_both_boundaries(): void
    {
        foreach ([new DateTimeInterval(new \DateTimeImmutable('2026-01-01')), new DateTimeInterval(null, new \DateTimeImmutable('2026-01-03'))] as $interval) {
            try {
                new WaybillPeriod($interval);
                self::fail('Открытый период маршрута должен быть отклонён.');
            } catch (AppException $exception) {
                self::assertStringContainsString('начала и окончания', $exception->getMessage());
            }
        }
    }

    public function test_trip_dates_include_both_exact_period_boundaries(): void
    {
        $first = $this->linked(null, '2026-01-01 00:00:00+00:00', 'Б');
        $last = $this->linked($first, '2026-01-03 23:59:59.999999+00:00', 'В');
        $draft = $this->draft('2026-01-01 00:00:00+00:00', '2026-01-03 23:59:59.999999+00:00');
        $draft->setTrips($first, $last);

        self::assertSame([$first, $last], $draft->trips());
    }

    public function test_trip_dates_outside_period_are_rejected(): void
    {
        foreach (['2025-12-31', '2026-01-04'] as $day) {
            $draft = $this->draft('2026-01-01', '2026-01-03');
            try {
                $draft->setTrips($this->linked(null, $day, 'Б'));
                self::fail('Поездка вне периода должна быть отклонена.');
            } catch (AppException $exception) {
                self::assertStringContainsString('вне периода путевого листа', $exception->getMessage());
            }
        }
    }

    public function test_location_and_cumulative_distance_at_end_of_day(): void
    {
        $first = $this->linked(null, '2026-01-02 08:00', 'Б');
        $second = $this->linked($first, '2026-01-02 18:00', 'В');
        $last = $this->linked($second, '2026-01-04', 'Г');
        $route = new Route($this->vehicle(), $first, $last);

        foreach ([
            ['2026-01-01', $first->departure(), 0],
            ['2026-01-02', $second->destination->location, 2],
            ['2026-01-03', $second->destination->location, 2],
            ['2026-01-04', $last->destination->location, 3],
            ['2026-01-05', $last->destination->location, 3],
        ] as [$day, $location, $distance]) {
            $date = new \DateTimeImmutable($day);
            self::assertSame($location, $route->locationAt($date));
            self::assertSame($distance, $route->distanceAt($date));
            self::assertSame(100 + $distance, $route->odometerAt($date, 100));
        }
    }

    public function test_empty_route_has_no_location_and_zero_distance(): void
    {
        $route = new Route($this->vehicle());
        $date = new \DateTimeImmutable('2026-01-02');
        self::assertNull($route->locationAt($date));
        self::assertNull($route->last());
        self::assertNull($route->predecessor());
        self::assertSame(0, $route->distanceAt($date));
        self::assertSame(123, $route->odometerAt($date, 123));
    }

    public function test_last_location_is_last_recorded_destination_even_for_future_trips(): void
    {
        $first = $this->linked(null, '2099-01-01', 'Б');
        $last = $this->linked($first, '2099-01-02', 'В');
        $route = new Route($this->vehicle(), $first, $last);

        self::assertSame($first->departure(), $route->locationAt(new \DateTimeImmutable('2098-12-31')));
        self::assertSame($last->destination->location, $route->last()->destination->location);
        self::assertSame($first->destination->location, $route->replaceSegment($last, $last)->last()->destination->location);
        self::assertSame($last->destination->location, $route->last()->destination->location);
    }

    public function test_waybill_period_detects_outside_dates_even_for_empty_route(): void
    {
        $route = new Route($this->vehicle());
        $period = WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-03'));
        foreach (['2025-12-31', '2026-01-04'] as $day) {
            $date = new \DateTimeImmutable($day);
            self::assertFalse($period->contains($date));
            self::assertNull($route->locationAt($date));
            self::assertSame(0, $route->distanceAt($date));
            self::assertSame(0, $route->odometerAt($date, 0));
        }
    }

    public function test_odometer_rejects_negative_initial_value(): void
    {
        $this->expectException(AppException::class);
        (new Route($this->vehicle()))->odometerAt(new \DateTimeImmutable('2026-01-01'), -1);
    }

    public function test_odometer_accepts_integer_limit_and_rejects_overflow(): void
    {
        $first = $this->linked(null, '2026-01-01', 'Б');
        $route = new Route($this->vehicle(), $first, $first);
        $date = new \DateTimeImmutable('2026-01-01');
        self::assertSame(PHP_INT_MAX, $route->odometerAt($date, PHP_INT_MAX - 1));
        $this->expectException(AppException::class);
        $route->odometerAt($date, PHP_INT_MAX);
    }

    public function test_draft_period_change_preserves_logs_and_revalidates_boundaries(): void
    {
        $manual = $this->linked(null, '2026-01-02', 'Б');
        $draft = $this->draft('2026-01-01', '2026-01-03');
        $draft->setTrips($manual);
        $route = $draft->route();
        $period = WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-02'), new \DateTimeImmutable('2026-01-04'));
        $draft->reviseDraft($period, null, null, $route);
        self::assertSame($period, $draft->period());
        self::assertSame([$manual], $draft->trips());

        foreach ([$this->interval('2026-01-03', '2026-01-04'), $this->interval('2025-12-31', '2026-01-01'), new DateTimeInterval(new \DateTimeImmutable('2026-01-01'))] as $invalid) {
            try {
                $draft->reviseDraft(new WaybillPeriod($invalid), null, null, $route);
                self::fail('Недопустимый период должен быть отклонён.');
            } catch (AppException) {
                self::assertSame([$manual], $draft->trips());
                self::assertSame($period, $draft->period());
            }
        }
    }

    public function test_replacing_segment_is_atomic_and_preserves_untouched_manual_logs(): void
    {
        $first = $this->linked(null, '2026-01-01', 'Б');
        $oldOut = $this->linked($first, '2026-01-02', 'В', origin: RouteLogOrigin::Automatic);
        $oldBack = $this->linked($oldOut, '2026-01-02', 'Б', origin: RouteLogOrigin::Automatic);
        $last = $this->linked($oldBack, '2026-01-03', 'Г');
        $route = new Route($this->vehicle(), $first, $last);
        $newOut = $this->linked($first, '2026-01-02', 'Д', origin: RouteLogOrigin::Automatic);
        $newBack = $this->linked($newOut, '2026-01-02', 'Б', origin: RouteLogOrigin::Automatic);

        $updated = iterator_to_array($route->replaceSegment($oldOut, $oldBack, $newOut, $newBack)->logs(), false);
        self::assertSame([$first, $newOut, $newBack], array_slice($updated, 0, 3));
        self::assertSame((string) $last->id, (string) $updated[3]->id);
        self::assertSame($newBack, $updated[3]->previous);
        self::assertSame([$first, $oldOut, $oldBack, $last], iterator_to_array($route->logs(), false));
        $removed = iterator_to_array($route->replaceSegment($oldOut, $oldBack)->logs(), false);
        self::assertSame((string) $last->id, (string) $removed[1]->id);
        self::assertSame($first, $removed[1]->previous);
        $inserted = iterator_to_array($route->insertAfter($first, $newOut, $newBack)->logs(), false);
        self::assertSame([$first, $newOut, $newBack], array_slice($inserted, 0, 3));
        self::assertSame([(string) $oldOut->id, (string) $oldBack->id, (string) $last->id], array_map(static fn (RouteLog $trip): string => (string) $trip->id, array_slice($inserted, 3)));
    }

    public function test_replacing_segment_rejects_invalid_ranges_and_invalid_result(): void
    {
        $first = $this->linked(null, '2026-01-01', 'Б');
        $last = $this->linked($first, '2026-01-03', 'В');
        $outside = $this->linked(null, '2026-01-01', 'Г');
        $route = new Route($this->vehicle(), $first, $last);
        $attempts = [
            fn () => $route->replaceSegment($outside, $outside),
            fn () => $route->replaceSegment($first, $outside),
            fn () => $route->replaceSegment($outside, $last),
            fn () => $route->replaceSegment($last, $first),
            fn () => $route->insertAfter($first, $this->linked(null, '2026-01-02', 'Г')),
            function () use ($route, $first): void {
                $out = $this->linked($first, '2026-01-04', 'Г');
                $route->insertAfter($first, $out, $this->linked($out, '2026-01-04', 'Б'));
            },
            function () use ($route, $first): void {
                $out = $this->linked($first, '2026-01-02', 'Г');
                $route->insertAfter($first, $out, $this->linked($out, '2026-01-01', 'Б'));
            },
            function () use ($route, $first): void {
                $out = $this->linked($first, '2026-01-02', 'Г', PHP_INT_MAX);
                $route->insertAfter($first, $out, $this->linked($out, '2026-01-02', 'Б'));
            },
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('Недопустимая замена должна быть отклонена.');
            } catch (AppException) {
                self::assertSame([$first, $last], iterator_to_array($route->logs(), false));
            }
        }
    }

    public function test_route_reads_predecessor_from_first_log(): void
    {
        $previous = $this->linked(null, '2025-12-31', 'Б');
        $first = $this->linked($previous, '2026-01-01', 'В');
        $route = new Route($this->vehicle(), $first, $first);

        self::assertSame($previous, $route->predecessor());
    }

    public function test_empty_route_keeps_predecessor_when_logs_are_inserted_and_removed(): void
    {
        $previous = $this->linked(null, '2025-12-31', 'Б');
        $route = new Route($this->vehicle(), predecessor: $previous);
        $trip = $this->linked($previous, '2026-01-01', 'В');

        self::assertSame($previous, $route->predecessor());
        self::assertSame([], iterator_to_array($route->logs(), false));

        $filled = $route->insertAfter($previous, $trip);
        self::assertSame($previous, $filled->predecessor());
        self::assertSame([$trip], iterator_to_array($filled->logs(), false));

        $emptied = $filled->replaceSegment($trip, $trip);
        self::assertSame($previous, $emptied->predecessor());
        self::assertSame([], iterator_to_array($emptied->logs(), false));
    }

    public function test_route_rejects_predecessor_from_another_vehicle(): void
    {
        $otherVehicle = new Vehicle(Uuid::v7(), 'Другая модель', new RegistrationNumber('B123BC77'), new \DateTimeImmutable('2025-01-01'), new Location('А'), 0, BigDecimal::zero());
        $otherTrip = new RouteLog(Uuid::v7(), $otherVehicle, null, new \DateTimeImmutable('2026-01-01'), new Destination(new Location('Б'), 1), RouteLogOrigin::Manual);

        $this->expectException(AppException::class);
        new Route($this->vehicle(), predecessor: $otherTrip);
    }

    public function test_route_rejects_mismatched_predecessor(): void
    {
        $previous = $this->linked(null, '2025-12-31', 'Б');
        $otherPrevious = $this->linked(null, '2025-12-31', 'В');
        $first = $this->linked($previous, '2026-01-01', 'Г');

        $this->expectException(AppException::class);
        new Route($this->vehicle(), $first, $first, $otherPrevious);
    }

    private function interval(string $from, string $to): DateTimeInterval
    {
        return new DateTimeInterval(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    private function vehicle(): Vehicle
    {
        if (null === $this->linkedVehicle) {
            $base = new Location('А');
            $vehicle = new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2025-01-01'), $base, 0, BigDecimal::zero());
            $driver = new DriverProfile(Uuid::v7(), 'owner', new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), $base);
            $vehicle->enter($driver);
            $this->linkedVehicle = $vehicle;
        }

        return $this->linkedVehicle;
    }

    private function linked(?RouteLog $previous, string $date, string $to, int $distance = 1, RouteLogOrigin $origin = RouteLogOrigin::Manual): RouteLog
    {
        return new RouteLog(Uuid::v7(), $this->vehicle(), $previous, new \DateTimeImmutable($date), new Destination(new Location($to), $distance), $origin);
    }

    private function draft(string $from, string $to): Waybill
    {
        return Waybill::createDraft(Uuid::v7(), 1, $this->vehicle(), $this->interval($from, $to));
    }
}
