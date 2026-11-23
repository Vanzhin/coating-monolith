<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Service;

use App\Shared\Domain\Aggregate\ValueObject\IntRange;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Destination;

final class WaybillRouteCombinationFinder
{
    public function find(int $remaining, int $availableDayCount, Destination ...$destinations): RouteFillSolution
    {
        if ([] === $destinations) {
            throw new AppException('Не настроены направления автоматических поездок.');
        }

        $options = $this->routeOptions($remaining, ...$destinations);
        if ([] === $options) {
            throw new AppException(sprintf('Невозможно набрать %d км из настроенных направлений.', $remaining));
        }
        shuffle($options);

        $minimumOption = PHP_INT_MAX;
        $maximumOption = 0;
        foreach ($options as $option) {
            $minimumOption = min($minimumOption, $option->distance());
            $maximumOption = max($maximumOption, $option->distance());
        }

        $current = [0 => ['' => RouteFillSolution::empty()]];
        $finalists = [];
        for ($day = 1; $day <= $availableDayCount; ++$day) {
            $next = [];
            $daysLeft = $availableDayCount - $day + 1;
            foreach ($current as $sum => $solutions) {
                if ($this->cannotReachTarget($sum, $remaining, $daysLeft, $minimumOption, $maximumOption)) {
                    continue;
                }
                foreach ($solutions as $solution) {
                    foreach ($options as $option) {
                        if ($option->distance() > $remaining - $sum) {
                            continue;
                        }
                        $newSum = $sum + $option->distance();
                        $candidate = $solution->withOption($option);
                        $coverageKey = $candidate->coverageKey();
                        $existing = $next[$newSum][$coverageKey] ?? null;
                        if (null === $existing
                            || $candidate->deviation() < $existing->deviation()
                            || ($candidate->deviation() === $existing->deviation() && $candidate->imbalance() < $existing->imbalance())) {
                            $next[$newSum][$coverageKey] = $candidate;
                        }
                    }
                }
            }
            if ([] === $next) {
                break;
            }
            foreach ($next[$remaining] ?? [] as $finalist) {
                $finalists[] = $finalist;
            }
            $current = $next;
        }

        if ([] === $finalists) {
            throw new AppException(sprintf('Невозможно набрать %d км из настроенных направлений за доступные рабочие дни.', $remaining));
        }

        $best = $finalists[0];
        foreach (array_slice($finalists, 1) as $candidate) {
            if ($this->isBetter($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /** @return list<RouteFillOption> */
    private function routeOptions(int $remaining, Destination ...$destinations): array
    {
        $options = [];
        $floorHalfRemaining = intdiv($remaining, 2);
        $maximumLeg = $floorHalfRemaining + $remaining % 2;
        foreach ($destinations as $direction => $destination) {
            $range = $this->distanceRange($destination->distance);
            $minimum = $range->start();
            $maximum = min($range->end(), $maximumLeg);
            if ($minimum > $floorHalfRemaining || $minimum > $maximum) {
                continue;
            }

            $total = 2 * $minimum;
            $lastTotal = $maximum > $floorHalfRemaining ? $remaining : 2 * $maximum;
            while (true) {
                $outward = intdiv($total, 2);
                $options[] = new RouteFillOption($direction, $destination, $outward, $total - $outward);
                if ($total === $lastTotal) {
                    break;
                }
                ++$total;
            }
        }

        return $options;
    }

    private function distanceRange(int $distance): IntRange
    {
        if ($distance <= 30) {
            return new IntRange(max(1, $distance - 5), $distance + 5);
        }
        if ($distance <= 100) {
            $deviation = intdiv($distance, 10);

            return new IntRange($distance - $deviation, $distance + $deviation);
        }

        $deviation = intdiv($distance, 20);
        $maximum = $distance > PHP_INT_MAX - $deviation ? PHP_INT_MAX : $distance + $deviation;

        return new IntRange($distance - $deviation, $maximum);
    }

    private function cannotReachTarget(int $sum, int $remaining, int $daysLeft, int $minimumOption, int $maximumOption): bool
    {
        $distanceLeft = $remaining - $sum;
        if ($minimumOption > $distanceLeft) {
            return true;
        }

        return $daysLeft <= intdiv(PHP_INT_MAX, $maximumOption)
            && $maximumOption * $daysLeft < $distanceLeft;
    }

    private function isBetter(RouteFillSolution $candidate, RouteFillSolution $current): bool
    {
        if ($candidate->coverage() !== $current->coverage()) {
            return $candidate->coverage() > $current->coverage();
        }
        if ($candidate->deviation() !== $current->deviation()) {
            return $candidate->deviation() < $current->deviation();
        }
        if ($candidate->pairCount() !== $current->pairCount()) {
            return $candidate->pairCount() < $current->pairCount();
        }

        return $candidate->imbalance() < $current->imbalance();
    }
}
