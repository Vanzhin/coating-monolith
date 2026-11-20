<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class LinkedRouteTest extends TestCase
{
    public function test_restores_unordered_trips_by_previous_including_equal_times(): void
    {
        $vehicle = $this->vehicle();
        $before = $this->log($vehicle, null, '2026-01-01', 'Б');
        $first = $this->log($vehicle, $before, '2026-01-02', 'В');
        $middle = $this->log($vehicle, $first, '2026-01-02', 'Г');
        $last = $this->log($vehicle, $middle, '2026-01-02', 'Д');

        $route = Route::fromUnorderedTrips($vehicle, $last, $first, $middle);

        self::assertSame([$first, $middle, $last], iterator_to_array($route->logs(), false));
        self::assertSame($before, $route->first()?->previous);
        self::assertSame(3, $route->distance());
    }

    public function test_restores_empty_route(): void
    {
        self::assertSame([], iterator_to_array(Route::fromUnorderedTrips($this->vehicle())->logs(), false));
    }

    public function test_rejects_unordered_trips_with_a_gap(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->log($vehicle, null, '2026-01-01', 'Б');
        $middle = $this->log($vehicle, $first, '2026-01-02', 'В');
        $last = $this->log($vehicle, $middle, '2026-01-03', 'Г');

        $this->expectException(AppException::class);
        Route::fromUnorderedTrips($vehicle, $first, $last);
    }

    public function test_rejects_unordered_trip_fork(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->log($vehicle, null, '2026-01-01', 'Б');
        $left = $this->log($vehicle, $first, '2026-01-02', 'В');
        $right = $this->log($vehicle, $first, '2026-01-02', 'Г');

        $this->expectException(AppException::class);
        Route::fromUnorderedTrips($vehicle, $right, $first, $left);
    }

    public function test_rejects_duplicate_unordered_trip(): void
    {
        $vehicle = $this->vehicle();
        $trip = $this->log($vehicle, null, '2026-01-01', 'Б');

        $this->expectException(AppException::class);
        Route::fromUnorderedTrips($vehicle, $trip, $trip);
    }

    public function test_rejects_unordered_trip_for_another_vehicle(): void
    {
        $trip = $this->log($this->vehicle(), null, '2026-01-01', 'Б');

        $this->expectException(AppException::class);
        Route::fromUnorderedTrips($this->vehicle(), $trip);
    }

    public function test_first_departure_comes_from_vehicle_and_next_from_previous_destination(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->log($vehicle, null, '2026-01-01', 'Б', 2);
        $next = $this->log($vehicle, $first, '2026-01-01', 'В', 2);

        self::assertSame($vehicle->initialLocation(), $first->departure());
        self::assertSame($first->destination->location, $next->departure());
        self::assertSame(2, $first->distanceInKilometers());
        self::assertSame(2, $next->distanceInKilometers());
    }

    public function test_rejects_previous_trip_of_another_vehicle(): void
    {
        $first = $this->log($this->vehicle(), null, '2026-01-01', 'Б');

        $this->expectException(AppException::class);
        $this->log($this->vehicle(), $first, '2026-01-02', 'В');
    }

    public function test_rejects_trip_before_vehicle_history(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('раньше начала истории автомобиля');

        $this->log($this->vehicle(), null, '2024-12-31 23:59:59+00:00', 'Б');
    }

    public function test_allows_trip_at_the_start_of_vehicle_history(): void
    {
        $trip = $this->log($this->vehicle(), null, '2025-01-01 00:00:00+00:00', 'Б');

        self::assertSame('2025-01-01 00:00:00+00:00', $trip->date->format('Y-m-d H:i:sP'));
    }

    public function test_compares_vehicle_history_start_as_an_instant_across_time_zones(): void
    {
        $trip = $this->log($this->vehicle(), null, '2025-01-01 03:00:00+03:00', 'Б');

        self::assertEquals(new \DateTimeImmutable('2025-01-01 00:00:00+00:00'), $trip->date);
    }

    public function test_rejects_earlier_instant_even_when_local_date_is_later(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('раньше начала истории автомобиля');

        $this->log($this->vehicle(), null, '2025-01-01 00:30:00+03:00', 'Б');
    }

    public function test_rejects_destination_equal_to_departure_even_for_distinct_location_objects(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->log($vehicle, null, '2026-01-01', 'Б');

        $this->expectException(AppException::class);
        $this->log($vehicle, $first, '2026-01-02', ' Б ');
    }

    public function test_rejects_first_trip_back_to_initial_location(): void
    {
        $this->expectException(AppException::class);
        $this->log($this->vehicle(), null, '2026-01-01', ' А ');
    }

    public function test_rejects_a_trip_earlier_than_its_previous_trip(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->log($vehicle, null, '2026-01-02', 'Б');

        $this->expectException(AppException::class);
        $this->log($vehicle, $first, '2026-01-01', 'В');
    }

    #[DataProvider('nonPositiveDistances')]
    public function test_rejects_non_positive_distance(int $distance): void
    {
        $this->expectException(AppException::class);
        $this->log($this->vehicle(), null, '2026-01-01', 'Б', $distance);
    }

    /** @return iterable<string, array{int}> */
    public static function nonPositiveDistances(): iterable
    {
        yield 'zero integer' => [0];
        yield 'negative integer' => [-1];
    }

    #[DataProvider('nonFiniteDistances')]
    public function test_rejects_non_integer_distance(float $distance): void
    {
        $this->expectException(\TypeError::class);
        $this->log($this->vehicle(), null, '2026-01-01', 'Б', $distance); // @phpstan-ignore-line
    }

    /** @return iterable<string, array{float}> */
    public static function nonFiniteDistances(): iterable
    {
        yield 'infinity' => [INF];
        yield 'not a number' => [NAN];
        yield 'zero float' => [0.0];
        yield 'negative fraction' => [-0.5];
        yield 'positive fraction' => [1.5];
    }

    public function test_selected_segment_excludes_earlier_trips_from_sum_and_iteration(): void
    {
        $vehicle = $this->vehicle();
        $before = $this->log($vehicle, null, '2026-01-01', 'Б', 100);
        $first = $this->log($vehicle, $before, '2026-01-02', 'В', 2);
        $last = $this->log($vehicle, $first, '2026-01-03', 'Г', 3);
        $route = new Route($vehicle, $first, $last);

        self::assertSame([$first, $last], iterator_to_array($route->logs(), false));
        self::assertSame(5, $route->distance());
        self::assertSame(0, $route->distanceAt(new \DateTimeImmutable('2026-01-01')));
        self::assertSame(2, $route->distanceAt(new \DateTimeImmutable('2026-01-02')));
        self::assertSame(105, $route->odometerAt(new \DateTimeImmutable('2026-01-03'), 100));
        self::assertSame($before->destination->location, $route->locationAt(new \DateTimeImmutable('2026-01-01')));
        self::assertSame($last->destination->location, $route->locationAt(new \DateTimeImmutable('2026-01-03')));
    }

    public function test_rejects_unrelated_segment_boundaries(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->log($vehicle, null, '2026-01-01', 'Б');
        $unrelated = $this->log($vehicle, null, '2026-01-01', 'В');

        $this->expectException(AppException::class);
        new Route($vehicle, $first, $unrelated);
    }

    public function test_route_distance_rejects_integer_overflow(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->log($vehicle, null, '2026-01-01', 'Б', PHP_INT_MAX);
        $last = $this->log($vehicle, $first, '2026-01-02', 'В', 1);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Общий пробег маршрута слишком велик');
        new Route($vehicle, $first, $last);
    }

    public function test_odometer_rejects_integer_overflow(): void
    {
        $vehicle = $this->vehicle();
        $trip = $this->log($vehicle, null, '2026-01-01', 'Б', 1);
        $route = new Route($vehicle, $trip, $trip);
        self::assertSame(PHP_INT_MAX, $route->odometerAt(new \DateTimeImmutable('2026-01-01'), PHP_INT_MAX - 1));

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('одометра слишком велико');
        $route->odometerAt(new \DateTimeImmutable('2026-01-01'), PHP_INT_MAX);
    }

    public function test_rejects_a_segment_with_only_one_boundary(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->log($vehicle, null, '2026-01-01', 'Б');

        $this->expectException(AppException::class);
        new Route($vehicle, $first);
    }

    public function test_inserting_a_return_trip_preserves_next_trip_and_original_route(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->log($vehicle, null, '2026-01-01', 'Б', 2);
        $next = $this->log($vehicle, $first, '2026-01-03', 'В', 3);
        $route = new Route($vehicle, $first, $next);
        $out = $this->log($vehicle, $first, '2026-01-02', 'Г', 2);
        $back = $this->log($vehicle, $out, '2026-01-02', 'Б', 2);

        $updated = $route->insertAfter($first, $out, $back);
        $logs = iterator_to_array($updated->logs(), false);

        self::assertSame([$first, $out, $back], array_slice($logs, 0, 3));
        self::assertNotSame($next, $logs[3]);
        self::assertSame($next->id, $logs[3]->id);
        self::assertSame($next->date, $logs[3]->date);
        self::assertSame($next->destination, $logs[3]->destination);
        self::assertSame($next->distanceInKilometers(), $logs[3]->distanceInKilometers());
        self::assertSame($next->origin, $logs[3]->origin);
        self::assertTrue($next->departure()->equals($logs[3]->departure()));
        self::assertSame($back, $logs[3]->previous);
        self::assertSame(9, $updated->distance());
        self::assertSame([$first, $next], iterator_to_array($route->logs(), false));
        self::assertSame($first, $next->previous);
        self::assertSame(5, $route->distance());
    }

    public function test_rejects_insertion_changing_next_departure_without_mutating_original(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->log($vehicle, null, '2026-01-01', 'Б');
        $next = $this->log($vehicle, $first, '2026-01-03', 'В');
        $route = new Route($vehicle, $first, $next);
        $inserted = $this->log($vehicle, $first, '2026-01-02', 'Г');

        try {
            $route->insertAfter($first, $inserted);
            self::fail('Вставка не должна менять отправление следующей поездки.');
        } catch (AppException) {
            self::assertSame([$first, $next], iterator_to_array($route->logs(), false));
            self::assertSame($first, $next->previous);
            self::assertSame(2, $route->distance());
        }
    }

    public function test_rejects_return_trip_inserted_after_the_next_trip_date(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->log($vehicle, null, '2026-01-01', 'Б');
        $next = $this->log($vehicle, $first, '2026-01-03', 'В');
        $route = new Route($vehicle, $first, $next);
        $out = $this->log($vehicle, $first, '2026-01-04', 'Г');
        $back = $this->log($vehicle, $out, '2026-01-04', 'Б');

        try {
            $route->insertAfter($first, $out, $back);
            self::fail('Вставка не должна нарушать порядок дат.');
        } catch (AppException) {
            self::assertSame([$first, $next], iterator_to_array($route->logs(), false));
            self::assertSame($first, $next->previous);
        }
    }

    private function vehicle(): Vehicle
    {
        return new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2025-01-01 00:00:00+00:00'), new Location('А'), 100, BigDecimal::of('20'));
    }

    private function log(Vehicle $vehicle, ?RouteLog $previous, string $date, string $destination, int $distance = 1): RouteLog
    {
        return new RouteLog(Uuid::v7(), $vehicle, $previous, new \DateTimeImmutable($date), new Destination(new Location($destination), $distance), RouteLogOrigin::Manual);
    }
}
