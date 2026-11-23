<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Service;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;
use App\Waybills\Domain\Aggregate\Waybill\OdometerRange;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use App\Waybills\Domain\Service\Calendar\ProductionCalendar;
use Symfony\Component\Uid\Uuid;

final class WaybillRouteFiller
{
    public function fill(
        RouteSettings $settings,
        WaybillPeriod $period,
        Route $fixedRoute,
        OdometerRange $odometer,
        ProductionCalendar $calendar,
        WaybillPeriod ...$excludedPeriods,
    ): WaybillRouteFillResult {
        try {
            $remaining = $odometer->distance() - $fixedRoute->distance();
            if ($remaining < 0) {
                throw new AppException('Пробег неизменяемых поездок превышает заданный диапазон одометра.');
            }
            if (0 === $remaining) {
                return WaybillRouteFillResult::success($fixedRoute);
            }

            $windows = $this->baseWindows($fixedRoute, $settings->base);
            if ([] === $windows) {
                throw new AppException('Нет доступных участков нахождения на базе.');
            }

            $availableDates = $this->availableDates($period, $calendar, $windows, $fixedRoute->vehicle()->initialAt(), ...$excludedPeriods);
            if ([] === $availableDates) {
                throw new AppException('Нет доступных рабочих дат для автоматических поездок.');
            }

            $solution = (new WaybillRouteCombinationFinder())->find($remaining, count($availableDates), ...$settings->destinations());

            return WaybillRouteFillResult::success($this->buildRoute($fixedRoute, $solution, $availableDates, $settings->base));
        } catch (AppException $exception) {
            return WaybillRouteFillResult::failure($exception->getMessage());
        }
    }

    /** @return list<array{gap: int, previous: ?RouteLog, after: ?\DateTimeImmutable, before: ?\DateTimeImmutable}> */
    private function baseWindows(Route $fixedRoute, Location $base): array
    {
        $fixedTrips = iterator_to_array($fixedRoute->logs(), false);
        $previous = $fixedRoute->predecessor();
        $location = null === $previous ? $fixedRoute->vehicle()->initialLocation() : $previous->destination->location;
        $after = $previous?->date;
        $windows = [];
        $gapCount = count($fixedTrips) + 1;
        for ($gap = 0; $gap < $gapCount; ++$gap) {
            $next = $fixedTrips[$gap] ?? null;
            if ($location->samePlace($base) && (null === $next || $next->departure()->samePlace($base))) {
                $windows[] = ['gap' => $gap, 'previous' => $previous, 'after' => $after, 'before' => $next?->date];
            }
            if (null !== $next) {
                $previous = $next;
                $location = $next->destination->location;
                $after = $next->date;
            }
        }

        return $windows;
    }

    /**
     * @param list<array{gap: int, previous: ?RouteLog, after: ?\DateTimeImmutable, before: ?\DateTimeImmutable}> $windows
     *
     * @return array<string, array{date: \DateTimeImmutable, gap: int, previous: ?RouteLog}>
     */
    private function availableDates(WaybillPeriod $period, ProductionCalendar $calendar, array $windows, \DateTimeImmutable $historyStart, WaybillPeriod ...$excludedPeriods): array
    {
        $availableDates = [];
        foreach ($calendar->workingDays($period->from(), $period->to()) as $calendarDay) {
            $date = $calendarDay->date;
            if (!$period->contains($date) || $date->format('Y-m-d') < $historyStart->setTimezone($date->getTimezone())->format('Y-m-d')) {
                continue;
            }
            foreach ($excludedPeriods as $excludedPeriod) {
                if ($excludedPeriod->contains($date)) {
                    continue 2;
                }
            }
            foreach ($windows as $window) {
                if (null !== $window['after'] && $date < $window['after']) {
                    continue;
                }
                if (null !== $window['before'] && $date > $window['before']) {
                    continue;
                }
                $key = $date->format('Y-m-d');
                $availableDates[$key] ??= ['date' => $date, 'gap' => $window['gap'], 'previous' => $window['previous']];
            }
        }

        return $availableDates;
    }

    /** @param array<string, array{date: \DateTimeImmutable, gap: int, previous: ?RouteLog}> $availableDates */
    private function buildRoute(Route $fixedRoute, RouteFillSolution $solution, array $availableDates, Location $base): Route
    {
        $keys = array_keys($availableDates);
        shuffle($keys);
        $selectedDates = [];
        foreach (array_slice($keys, 0, $solution->pairCount()) as $key) {
            $selectedDates[] = $availableDates[$key];
        }
        usort($selectedDates, static function (array $left, array $right): int {
            if ($left['gap'] === $right['gap']) {
                return $right['date'] <=> $left['date'];
            }

            return $right['gap'] <=> $left['gap'];
        });

        $options = $this->randomizedOptions($solution->options());
        $route = $fixedRoute;
        foreach ($selectedDates as $index => $selectedDate) {
            $option = $options[$index];
            $previous = $selectedDate['previous'];
            $outward = new RouteLog(
                Uuid::v7(),
                $fixedRoute->vehicle(),
                $previous,
                $selectedDate['date'],
                new Destination($option->destination->location, $option->outwardDistance, $option->destination->enterprise),
                RouteLogOrigin::Automatic,
            );
            $back = new RouteLog(Uuid::v7(), $fixedRoute->vehicle(), $outward, $selectedDate['date'], new Destination($base, $option->returnDistance), RouteLogOrigin::Automatic);
            $route = $route->insertAfter($previous, $outward, $back);
        }

        return $route;
    }

    /**
     * @param list<RouteFillOption> $options
     *
     * @return list<RouteFillOption>
     */
    private function randomizedOptions(array $options): array
    {
        $remaining = $options;
        $result = [];
        $previousDirection = null;
        while ([] !== $remaining) {
            $candidates = [];
            foreach ($remaining as $index => $option) {
                if ($option->direction !== $previousDirection) {
                    $candidates[] = $index;
                }
            }
            if ([] === $candidates) {
                $candidates = array_keys($remaining);
            }
            $candidate = $candidates[random_int(0, count($candidates) - 1)];
            $result[] = $remaining[$candidate];
            $previousDirection = $remaining[$candidate]->direction;
            array_splice($remaining, $candidate, 1);
        }

        return $result;
    }
}
