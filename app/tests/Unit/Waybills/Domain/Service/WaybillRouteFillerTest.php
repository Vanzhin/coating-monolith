<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Service;

use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\OdometerRange;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use App\Waybills\Domain\Service\Calendar\ProductionCalendar;
use App\Waybills\Domain\Service\WaybillRouteFiller;
use App\Waybills\Domain\Service\WaybillRouteFillResult;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillRouteFillerTest extends TestCase
{
    public function test_odd_remainder_creates_unequal_pair_and_preserves_input(): void
    {
        $settings = $this->settings(new Destination(new Location('Склад'), 10, 'Склад'));
        $route = $this->route($this->vehicle());

        $result = $this->fill($settings, $route, 1021);
        $filled = $this->assertFilled($result);
        $logs = $this->logs($filled);

        self::assertCount(2, $logs);
        self::assertSame([10, 11], array_map(static fn (RouteLog $log): int => $log->distanceInKilometers(), $logs));
        self::assertSame([RouteLogOrigin::Automatic, RouteLogOrigin::Automatic], array_map(static fn (RouteLog $log): RouteLogOrigin => $log->origin, $logs));
        self::assertSame('Склад', $logs[0]->destination->enterprise);
        self::assertNull($logs[1]->destination->enterprise);
        self::assertTrue($logs[1]->destination->location->equals($settings->base));
        self::assertEquals($logs[0]->date, $logs[1]->date);
        self::assertSame(21, $filled->distance());
        self::assertSame([], $this->logs($route));
    }

    public function test_distance_range_boundaries(): void
    {
        $cases = [
            30 => [25, 35],
            31 => [28, 34],
            100 => [90, 110],
            101 => [96, 106],
        ];
        foreach ($cases as $defaultDistance => [$minimum, $maximum]) {
            foreach ([$minimum, $maximum] as $distance) {
                $settings = $this->settings(new Destination(new Location('Направление'), $defaultDistance));
                $route = $this->route($this->vehicle());
                $result = $this->fill($settings, $route, 1000 + 2 * $distance);
                $logs = $this->logs($this->assertFilled($result));

                self::assertSame([$distance, $distance], array_map(static fn (RouteLog $log): int => $log->distanceInKilometers(), $logs), "Эталон {$defaultDistance}, плечо {$distance}");
            }
        }
    }

    public function test_all_directions_have_priority_over_fewer_days(): void
    {
        $settings = $this->settings(
            new Destination(new Location('Ближнее'), 10),
            new Destination(new Location('Дальнее'), 20),
        );

        $filled = $this->assertFilled($this->fill($settings, $this->route($this->vehicle()), 1100));
        $logs = $this->logs($filled);
        $outwardCities = array_map(static fn (RouteLog $log): string => $log->destination->location->name, $this->automaticOutward($filled, $settings->base));
        sort($outwardCities);

        self::assertCount(6, $logs);
        self::assertSame(100, $filled->distance());
        self::assertSame(['Ближнее', 'Дальнее', 'Дальнее'], $outwardCities);
        self::assertCount(3, array_unique(array_map(static fn (RouteLog $log): string => $log->date->format('Y-m-d'), $logs)));
    }

    public function test_compact_solution_wins_when_direction_coverage_is_equal(): void
    {
        $settings = $this->settings(
            new Destination(new Location('Короткое'), 10),
            new Destination(new Location('Подходящее'), 20),
        );

        $filled = $this->assertFilled($this->fill($settings, $this->route($this->vehicle()), 1035));
        $logs = $this->logs($filled);

        self::assertCount(2, $logs);
        self::assertSame('Подходящее', $logs[0]->destination->location->name);
        self::assertSame([17, 18], array_map(static fn (RouteLog $log): int => $log->distanceInKilometers(), $logs));
    }

    public function test_single_suitable_direction_is_used_when_others_are_too_long(): void
    {
        $settings = $this->settings(
            new Destination(new Location('Короткое'), 10),
            new Destination(new Location('Среднее'), 40),
            new Destination(new Location('Дальнее'), 100),
        );

        $filled = $this->assertFilled($this->fill($settings, $this->route($this->vehicle()), 1020));
        $logs = $this->logs($filled);

        self::assertCount(2, $logs);
        self::assertSame('Короткое', $logs[0]->destination->location->name);
        self::assertSame([10, 10], array_map(static fn (RouteLog $log): int => $log->distanceInKilometers(), $logs));
    }

    public function test_exact_search_avoids_greedy_dead_end(): void
    {
        $settings = $this->settings(
            new Destination(new Location('Дальнее'), 100),
            new Destination(new Location('Среднее'), 40),
        );

        $filled = $this->assertFilled($this->fill($settings, $this->route($this->vehicle()), 1260));
        $logs = $this->logs($filled);
        $outwardCities = array_map(static fn (RouteLog $log): string => $log->destination->location->name, $this->automaticOutward($filled, $settings->base));

        self::assertCount(4, $logs);
        self::assertSame(260, $filled->distance());
        self::assertCount(2, $outwardCities);
        self::assertContains('Дальнее', $outwardCities);
        self::assertContains('Среднее', $outwardCities);
    }

    public function test_only_required_dates_are_used_with_one_pair_per_day(): void
    {
        $settings = $this->settings(new Destination(new Location('Направление'), 10));
        $calendar = new ProductionCalendar();
        $period = $this->period();

        $filled = $this->assertFilled($this->fill($settings, $this->route($this->vehicle()), 1060, $period));
        $logs = $this->logs($filled);
        $outwardLogs = $this->automaticOutward($filled, $settings->base);
        $dates = array_map(static fn (RouteLog $log): string => $log->date->format('Y-m-d'), $outwardLogs);

        self::assertCount(6, $logs);
        self::assertCount(3, $dates);
        self::assertCount(3, array_unique($dates));
        self::assertGreaterThan(3, count($calendar->workingDays($period->from(), $period->to())));
        foreach ($outwardLogs as $log) {
            self::assertFalse($calendar->isDayOff($log->date));
        }
    }

    public function test_manual_trip_over_several_days_blocks_automatic_trips_from_base(): void
    {
        $settings = $this->settings(new Destination(new Location('Направление'), 20));
        $vehicle = $this->vehicle();
        $outward = $this->trip($vehicle, null, '2026-02-02', 'Командировка', 10);
        $return = $this->trip($vehicle, $outward, '2026-02-04', 'База', 10);
        $route = $this->route($vehicle, null, $outward, $return);

        $filled = $this->assertFilled($this->fill($settings, $route, 1060));
        $logs = $this->logs($filled);
        $automatic = array_values(array_filter($logs, static fn (RouteLog $log): bool => RouteLogOrigin::Automatic === $log->origin));
        $manual = $this->manualTrips($filled);

        self::assertCount(4, $logs);
        self::assertCount(2, $automatic);
        self::assertNotSame('2026-02-03', $automatic[0]->date->format('Y-m-d'));
        self::assertSame($outward->id, $manual[0]->id);
        self::assertSame($return->id, $manual[1]->id);
        self::assertSame(60, $filled->distance());
        self::assertCount(2, $this->logs($route));
    }

    public function test_vehicle_history_start_limits_available_dates(): void
    {
        $settings = $this->settings(new Destination(new Location('Направление'), 10));
        $vehicle = $this->vehicle(initialAt: '2026-02-04 15:00:00');

        $filled = $this->assertFilled($this->fill($settings, $this->route($vehicle), 1020, $this->period('2026-02-02', '2026-02-04')));
        $logs = $this->logs($filled);
        self::assertSame('2026-02-04', $logs[0]->date->format('Y-m-d'));

        $result = $this->fill($settings, $this->route($vehicle), 1020, $this->period('2026-02-02', '2026-02-03'));
        self::assertStringContainsString('Нет доступных рабочих дат', $this->assertFailure($result));
    }

    public function test_same_location_id_allows_automatic_pair_after_address_correction(): void
    {
        $id = Uuid::v7();
        $settings = new RouteSettings(
            new Location('Россия', 'Екаетринбург', id: $id),
            new Destination(new Location('Россия', 'Березовский'), 10),
        );
        $vehicle = $this->vehicle(new Location('Россия', 'Екатеринбург', id: $id));

        $filled = $this->assertFilled($this->fill($settings, $this->route($vehicle), 1020, $this->period('2026-02-02', '2026-02-02')));
        $logs = $this->logs($filled);

        self::assertCount(2, $logs);
        self::assertTrue($logs[0]->departure()->samePlace($settings->base));
        self::assertTrue($logs[1]->destination->location->samePlace($settings->base));
        self::assertSame($settings->base, $logs[1]->destination->location);
    }

    public function test_automatic_pairs_before_manual_trip_return_to_its_original_departure_snapshot(): void
    {
        $id = Uuid::v7();
        $original = new Location('Россия', 'Москва', 'Лесная', '1', 'Старый склад', 55.7, 37.6, $id);
        $updatedBase = new Location('Россия', 'Москва', 'Новая', '2', 'Новый склад', 55.8, 37.7, $id);
        $settings = new RouteSettings($updatedBase, new Destination(new Location('Направление'), 10));
        $vehicle = $this->vehicle($original);
        $manual = $this->trip($vehicle, null, '2026-02-04', 'Командировка', 5);
        $route = $this->route($vehicle, null, $manual);

        $filled = $this->assertFilled($this->fill($settings, $route, 1045, $this->period('2026-02-02', '2026-02-03')));
        $logs = $this->logs($filled);

        self::assertCount(5, $logs);
        self::assertSame($original, $logs[1]->destination->location);
        self::assertSame($original, $logs[3]->destination->location);
        self::assertSame($manual->id, $logs[4]->id);
        self::assertSame($manual->date, $logs[4]->date);
        self::assertSame($manual->destination, $logs[4]->destination);
        self::assertSame($original, $logs[4]->departure());
        self::assertSame([$manual], $this->logs($route));
        self::assertSame(45, $filled->distance());
    }

    public function test_automatic_pair_preserves_legacy_departure_snapshot_from_predecessor(): void
    {
        $original = new Location('Россия', 'Москва', 'Лесная', '1', 'Старый склад', 55.7, 37.6);
        $updatedBase = new Location('Россия', 'Москва', 'Лесная', '1', 'Новый склад', 55.8, 37.7);
        $settings = new RouteSettings($updatedBase, new Destination(new Location('Направление'), 10));
        $vehicle = $this->vehicle('Старт');
        $previous = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-30'), new Destination($original, 5), RouteLogOrigin::Manual);
        $manual = $this->trip($vehicle, $previous, '2026-02-02 18:00', 'Командировка', 5);
        $route = $this->route($vehicle, $previous, $manual);

        $filled = $this->assertFilled($this->fill($settings, $route, 1025, $this->period('2026-02-02', '2026-02-02')));
        $logs = $this->logs($filled);

        self::assertCount(3, $logs);
        self::assertSame($previous, $logs[0]->previous);
        self::assertSame($original, $logs[1]->destination->location);
        self::assertSame($manual->id, $logs[2]->id);
        self::assertSame($original, $logs[2]->departure());
        self::assertSame($previous, $filled->predecessor());
        self::assertSame([$manual], $this->logs($route));
    }

    public function test_automatic_pair_between_manual_trips_preserves_next_departure_snapshot(): void
    {
        $id = Uuid::v7();
        $original = new Location('Россия', 'Москва', 'Лесная', '1', 'Старый склад', 55.7, 37.6, $id);
        $updatedBase = new Location('Россия', 'Москва', 'Новая', '2', 'Новый склад', 55.8, 37.7, $id);
        $settings = new RouteSettings($updatedBase, new Destination(new Location('Направление'), 10));
        $vehicle = $this->vehicle('Старт');
        $first = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-02-02'), new Destination($original, 5), RouteLogOrigin::Manual);
        $next = $this->trip($vehicle, $first, '2026-02-03 18:00', 'Командировка', 5);
        $route = $this->route($vehicle, null, $first, $next);

        $filled = $this->assertFilled($this->fill($settings, $route, 1030, $this->period('2026-02-03', '2026-02-03')));
        $logs = $this->logs($filled);

        self::assertCount(4, $logs);
        self::assertSame($first, $logs[0]);
        self::assertSame($original, $logs[2]->destination->location);
        self::assertSame($next->id, $logs[3]->id);
        self::assertSame($original, $logs[3]->departure());
        self::assertSame([$first, $next], $this->logs($route));
    }

    public function test_working_saturday_and_transferred_weekend_are_respected(): void
    {
        $settings = $this->settings(new Destination(new Location('Направление'), 10));
        $period = $this->period('2027-02-20', '2027-02-22');

        $filled = $this->assertFilled($this->fill($settings, $this->route($this->vehicle()), 1020, $period));
        $logs = $this->logs($filled);

        self::assertSame('2027-02-20', $logs[0]->date->format('Y-m-d'));
    }

    public function test_unknown_calendar_year_uses_regular_weekends(): void
    {
        $settings = $this->settings(new Destination(new Location('Направление'), 10));
        $period = $this->period('2028-05-13', '2028-05-15');

        $filled = $this->assertFilled($this->fill($settings, $this->route($this->vehicle()), 1020, $period));
        $logs = $this->logs($filled);

        self::assertSame('2028-05-15', $logs[0]->date->format('Y-m-d'));
    }

    public function test_absence_can_make_fill_impossible(): void
    {
        $settings = $this->settings(new Destination(new Location('Направление'), 10));
        $period = $this->period('2026-02-02', '2026-02-02');
        $absence = $this->period('2026-02-02', '2026-02-02');

        $result = $this->fill($settings, $this->route($this->vehicle()), 1020, $period, $absence);

        self::assertStringContainsString('Нет доступных рабочих дат', $this->assertFailure($result));
    }

    public function test_zero_remainder_returns_fixed_route_without_directions(): void
    {
        $settings = $this->settings();
        $route = $this->route($this->vehicle());

        $zeroResult = $this->fill($settings, $route, 1000);
        self::assertSame($route, $this->assertFilled($zeroResult));

        $positiveResult = $this->fill($settings, $route, 1020);
        self::assertStringContainsString('направления', $this->assertFailure($positiveResult));
    }

    public function test_empty_route_uses_predecessor_location(): void
    {
        $settings = $this->settings(new Destination(new Location('Направление'), 10));
        $vehicle = $this->vehicle('Старт');
        $previous = $this->trip($vehicle, null, '2025-12-31', 'База', 5);
        $route = $this->route($vehicle, $previous);

        $filled = $this->assertFilled($this->fill($settings, $route, 1020));
        $logs = $this->logs($filled);

        self::assertSame($previous, $logs[0]->previous);
        self::assertTrue($logs[0]->departure()->equals($settings->base));
    }

    public function test_insufficient_dates_and_excess_fixed_distance_return_failures(): void
    {
        $settings = $this->settings(new Destination(new Location('Направление'), 10));
        $oneDay = $this->period('2026-02-02', '2026-02-02');
        $result = $this->fill($settings, $this->route($this->vehicle()), 1060, $oneDay);
        self::assertStringContainsString('Невозможно набрать 60 км', $this->assertFailure($result));

        $vehicle = $this->vehicle();
        $manual = $this->trip($vehicle, null, '2026-02-02', 'Ручная', 50);
        $result = $this->fill($settings, $this->route($vehicle, null, $manual), 1020);
        self::assertStringContainsString('неизменяемых поездок', $this->assertFailure($result));
    }

    public function test_realistic_month_distance_is_calculated(): void
    {
        $settings = $this->settings(
            new Destination(new Location('А'), 14),
            new Destination(new Location('Б'), 30),
            new Destination(new Location('В'), 50),
        );
        $calendar = new ProductionCalendar();
        $period = $this->period();

        $filled = $this->assertFilled($this->fill($settings, $this->route($this->vehicle()), 2036, $period));
        $outwardLogs = $this->automaticOutward($filled, $settings->base);
        $dates = array_map(static fn (RouteLog $log): string => $log->date->format('Y-m-d'), $outwardLogs);

        self::assertSame(1036, $filled->distance());
        self::assertNotSame([], $outwardLogs);
        self::assertCount(count($outwardLogs), array_unique($dates));
        self::assertLessThanOrEqual(count($calendar->workingDays($period->from(), $period->to())), count($outwardLogs));
    }

    private function fill(RouteSettings $settings, Route $route, int $target, ?WaybillPeriod $period = null, WaybillPeriod ...$excludedPeriods): WaybillRouteFillResult
    {
        return (new WaybillRouteFiller())->fill(
            $settings,
            $period ?? $this->period(),
            $route,
            new OdometerRange(1000, $target),
            new ProductionCalendar(),
            ...$excludedPeriods,
        );
    }

    private function assertFilled(WaybillRouteFillResult $result): Route
    {
        self::assertTrue($result->isSuccessful(), $result->error() ?? 'Заполнение завершилось ошибкой.');
        self::assertNull($result->error());
        $route = $result->route();
        self::assertInstanceOf(Route::class, $route);

        return $route;
    }

    private function assertFailure(WaybillRouteFillResult $result): string
    {
        self::assertFalse($result->isSuccessful());
        self::assertNull($result->route());
        self::assertIsString($result->error());
        self::assertNotSame('', $result->error());

        return $result->error();
    }

    /** @return list<RouteLog> */
    private function logs(Route $route): array
    {
        return iterator_to_array($route->logs(), false);
    }

    /** @return list<RouteLog> */
    private function automaticOutward(Route $route, Location $base): array
    {
        return array_values(array_filter(
            $this->logs($route),
            static fn (RouteLog $log): bool => RouteLogOrigin::Automatic === $log->origin && !$log->destination->location->equals($base),
        ));
    }

    /** @return list<RouteLog> */
    private function manualTrips(Route $route): array
    {
        return array_values(array_filter($this->logs($route), static fn (RouteLog $log): bool => RouteLogOrigin::Manual === $log->origin));
    }

    private function settings(Destination ...$destinations): RouteSettings
    {
        return new RouteSettings(new Location('База'), ...$destinations);
    }

    private function vehicle(string|Location $initialLocation = 'База', string $initialAt = '2025-01-01'): Vehicle
    {
        $initialLocation = is_string($initialLocation) ? new Location($initialLocation) : $initialLocation;

        return new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable($initialAt), $initialLocation, 1000, BigDecimal::zero());
    }

    private function route(Vehicle $vehicle, ?RouteLog $predecessor = null, RouteLog ...$trips): Route
    {
        $last = [] === $trips ? null : $trips[array_key_last($trips)];

        return new Route($vehicle, $trips[0] ?? null, $last, predecessor: $predecessor);
    }

    private function trip(Vehicle $vehicle, ?RouteLog $previous, string $date, string $destination, int $distance): RouteLog
    {
        return new RouteLog(Uuid::v7(), $vehicle, $previous, new \DateTimeImmutable($date), new Destination(new Location($destination), $distance), RouteLogOrigin::Manual);
    }

    private function period(string $from = '2026-02-01', string $to = '2026-02-28'): WaybillPeriod
    {
        return WaybillPeriod::fromDates(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }
}
