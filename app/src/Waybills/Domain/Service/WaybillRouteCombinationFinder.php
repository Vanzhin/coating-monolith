<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Service;

use App\Shared\Domain\Aggregate\ValueObject\IntRange;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Destination;
use Brick\Math\BigInteger;

final class WaybillRouteCombinationFinder
{
    public function find(int $remaining, int $availableDayCount, Destination ...$destinations): RouteFillSolution
    {
        if ([] === $destinations) {
            throw new AppException('Не настроены направления автоматических поездок.');
        }

        $directions = $this->directions($remaining, ...$destinations);
        if ([] === $directions) {
            throw new AppException(sprintf('Невозможно набрать %d км из настроенных направлений.', $remaining));
        }
        shuffle($directions);

        $minimumDeviationScore = $this->minimumDeviationScore($remaining, $directions);
        $directionCount = count($directions);
        $suffixMaximum = array_fill(0, $directionCount + 1, 0);
        $suffixReferenceMaximum = array_fill(0, $directionCount + 1, 0);
        for ($index = $directionCount - 1; $index >= 0; --$index) {
            $suffixMaximum[$index] = max($directions[$index]['maximum'], $suffixMaximum[$index + 1]);
            $suffixReferenceMaximum[$index] = max($directions[$index]['destination']->distance, $suffixReferenceMaximum[$index + 1]);
        }

        for ($targetCoverage = min($directionCount, $availableDayCount); $targetCoverage >= 1; --$targetCoverage) {
            $best = null;
            $equalBestCount = 0;
            $visited = [];
            $search = function (
                int $index,
                int $daysLeft,
                array $counts,
                int $minimum,
                int $maximum,
                int|BigInteger $reference,
                int $coverage,
                int $minimumUse,
                int $maximumUse,
            ) use (
                &$search,
                &$best,
                &$equalBestCount,
                &$visited,
                $directions,
                $directionCount,
                $suffixMaximum,
                $suffixReferenceMaximum,
                $remaining,
                $availableDayCount,
                $minimumDeviationScore,
                $targetCoverage,
            ): void {
                $directionsLeft = $directionCount - $index;
                $pairBudget = $daysLeft;
                if (null !== $best && 0 === $this->compareNumbers($best['deviation'], $minimumDeviationScore)) {
                    $pairBudget = min($pairBudget, $best['pairs'] - ($availableDayCount - $daysLeft));
                }
                if ($pairBudget < 0 || $minimum > $remaining) {
                    return;
                }
                if ($coverage > $targetCoverage || $coverage + min($directionsLeft, $pairBudget) < $targetCoverage) {
                    return;
                }

                $distanceLeft = $remaining - $maximum;
                if ($distanceLeft > 0) {
                    $maximumOption = $suffixMaximum[$index];
                    if ($pairBudget <= 0 || 0 === $maximumOption || $pairBudget < intdiv($distanceLeft - 1, $maximumOption) + 1) {
                        return;
                    }
                }

                if (null !== $best && !$this->canMatchDeviation($remaining, $reference, $suffixReferenceMaximum[$index], $pairBudget, $best['deviation'])) {
                    return;
                }

                // These values completely determine the score of every possible continuation.
                $state = implode(':', [$index, $daysLeft, $minimum, $maximum, $reference, $coverage, $minimumUse, $maximumUse]);
                if (isset($visited[$state])) {
                    return;
                }
                $visited[$state] = true;

                if ($index === $directionCount) {
                    if ($coverage !== $targetCoverage || $minimum > $remaining || $maximum < $remaining) {
                        return;
                    }
                    $candidate = [
                        'counts' => $counts,
                        'deviation' => $this->deviationScore($remaining, $reference),
                        'pairs' => $availableDayCount - $daysLeft,
                        'imbalance' => $maximumUse - $minimumUse,
                    ];
                    $comparison = null === $best ? -1 : $this->compare($candidate, $best);
                    if ($comparison < 0) {
                        $best = $candidate;
                        $equalBestCount = 1;
                    } elseif (0 === $comparison && 0 === mt_rand(0, $equalBestCount++)) {
                        $best = $candidate;
                    }

                    return;
                }

                $direction = $directions[$index];
                $minimumCount = $coverage + $directionsLeft - 1 < $targetCoverage ? 1 : 0;
                $maximumCount = min($pairBudget, intdiv($remaining - $minimum, $direction['minimum']));
                if ($minimumCount > $maximumCount) {
                    return;
                }

                // Rotate the entire range rather than materialising and shuffling it.
                $countRange = $maximumCount - $minimumCount + 1;
                $offset = 1 === $countRange ? 0 : mt_rand(0, $countRange - 1);
                for ($step = 0; $step < $countRange; ++$step) {
                    $count = $minimumCount + ($offset + $step) % $countRange;
                    $nextCounts = $counts;
                    $nextCounts[$index] = $count;
                    $maximumLeft = $remaining - $maximum;
                    $nextMaximum = $count > intdiv($maximumLeft, $direction['maximum'])
                        ? $remaining
                        : $maximum + $count * $direction['maximum'];
                    $search(
                        $index + 1,
                        $daysLeft - $count,
                        $nextCounts,
                        $minimum + $count * $direction['minimum'],
                        $nextMaximum,
                        $this->addReference($reference, $direction['destination']->distance, $count),
                        $coverage + ($count > 0 ? 1 : 0),
                        0 === $count ? $minimumUse : (0 === $minimumUse ? $count : min($minimumUse, $count)),
                        max($maximumUse, $count),
                    );
                }
            };

            $search(0, $availableDayCount, [], 0, 0, 0, 0, 0, 0);
            $search = null;
            if (null !== $best) {
                return $this->buildSolution($remaining, $directions, $best['counts']);
            }
        }

        throw new AppException(sprintf('Невозможно набрать %d км из настроенных направлений за доступные рабочие дни.', $remaining));
    }

    /**
     * @return list<array{direction: int, destination: Destination, minimum: int, maximum: int}>
     */
    private function directions(int $remaining, Destination ...$destinations): array
    {
        $directions = [];
        $floorHalfRemaining = intdiv($remaining, 2);
        foreach ($destinations as $direction => $destination) {
            $range = $this->distanceRange($destination->distance);
            $minimum = $range->start();
            if ($minimum > $floorHalfRemaining) {
                continue;
            }
            $maximum = $range->end() > $floorHalfRemaining ? $remaining : 2 * $range->end();
            $directions[] = [
                'direction' => $direction,
                'destination' => $destination,
                'minimum' => 2 * $minimum,
                'maximum' => $maximum,
            ];
        }

        return $directions;
    }

    /**
     * @param list<array{direction: int, destination: Destination, minimum: int, maximum: int}> $directions
     * @param list<int>                                                                         $counts
     */
    private function buildSolution(int $remaining, array $directions, array $counts): RouteFillSolution
    {
        $pairs = [];
        $baseTotal = 0;
        $minimumTotal = 0;
        foreach ($directions as $index => $direction) {
            for ($use = 0; $use < ($counts[$index] ?? 0); ++$use) {
                $distance = $direction['destination']->distance;
                $reference = $distance > intdiv($remaining, 2) ? $remaining : 2 * $distance;
                $total = min($reference, $direction['maximum']);
                $pairs[] = [...$direction, 'total' => $total];
                $baseTotal += min($total, $remaining - $baseTotal);
                $minimumTotal += $direction['minimum'];
            }
        }

        $belowReference = $baseTotal === $remaining;
        $difference = $remaining - ($belowReference ? $minimumTotal : $baseTotal);
        foreach ($pairs as &$pair) {
            if ($belowReference) {
                $pair['capacity'] = $pair['total'] - $pair['minimum'];
                $pair['total'] = $pair['minimum'];
            } else {
                $pair['capacity'] = $pair['maximum'] - $pair['total'];
            }
        }
        unset($pair);

        // Saturated suffix capacities keep every random choice feasible without overflowing.
        $suffixCapacity = array_fill(0, count($pairs) + 1, 0);
        for ($index = count($pairs) - 1; $index >= 0; --$index) {
            $suffixCapacity[$index] = $suffixCapacity[$index + 1]
                + min($pairs[$index]['capacity'], $difference - $suffixCapacity[$index + 1]);
        }
        foreach ($pairs as $index => &$pair) {
            $minimumChange = max(0, $difference - $suffixCapacity[$index + 1]);
            $maximumChange = min($difference, $pair['capacity']);
            $change = $minimumChange === $maximumChange ? $minimumChange : mt_rand($minimumChange, $maximumChange);
            $pair['total'] += $change;
            $difference -= $change;
        }
        unset($pair);

        $solution = RouteFillSolution::empty();
        foreach ($pairs as $pair) {
            $outward = intdiv($pair['total'], 2);
            $solution = $solution->withOption(new RouteFillOption($pair['direction'], $pair['destination'], $outward, $pair['total'] - $outward));
        }

        return $solution;
    }

    /**
     * @param array{counts: list<int>, deviation: int|BigInteger, pairs: int, imbalance: int} $candidate
     * @param array{counts: list<int>, deviation: int|BigInteger, pairs: int, imbalance: int} $current
     */
    private function compare(array $candidate, array $current): int
    {
        $comparison = $this->compareNumbers($candidate['deviation'], $current['deviation']);

        return $comparison ?: ($candidate['pairs'] <=> $current['pairs']) ?: ($candidate['imbalance'] <=> $current['imbalance']);
    }

    private function canMatchDeviation(int $remaining, int|BigInteger $reference, int $maximumDistance, int $pairBudget, int|BigInteger $bestDeviation): bool
    {
        $half = intdiv($remaining, 2);
        if ($this->compareNumbers($reference, $half) > 0) {
            return $this->compareNumbers($this->deviationScore($remaining, $reference), $bestDeviation) <= 0;
        }

        $maximumReference = $this->addReference($reference, $maximumDistance, $pairBudget);
        if ($this->compareNumbers($maximumReference, $half) < 0) {
            return $this->compareNumbers($this->deviationScore($remaining, $maximumReference), $bestDeviation) <= 0;
        }

        return true;
    }

    private function compareNumbers(int|BigInteger $left, int|BigInteger $right): int
    {
        if ($left instanceof BigInteger) {
            return $left->compareTo($right);
        }
        if ($right instanceof BigInteger) {
            return -$right->compareTo($left);
        }

        return $left <=> $right;
    }

    private function addReference(int|BigInteger $reference, int $distance, int $count): int|BigInteger
    {
        if (0 === $count) {
            return $reference;
        }
        if (is_int($reference) && $distance <= intdiv(PHP_INT_MAX - $reference, $count)) {
            return $reference + $distance * $count;
        }

        return BigInteger::of($reference)->plus(BigInteger::of($distance)->multipliedBy($count));
    }

    private function deviationScore(int $remaining, int|BigInteger $reference): int|BigInteger
    {
        // All reference totals are even, so their deviations have the same parity.
        // Comparing their halves is exact and avoids overflowing when doubling a reference.
        $half = intdiv($remaining, 2);
        if ($reference instanceof BigInteger) {
            return $reference->minus($half)->minus($remaining % 2);
        }

        return $reference <= $half ? $half - $reference : $reference - $half - $remaining % 2;
    }

    /** @param list<array{direction: int, destination: Destination, minimum: int, maximum: int}> $directions */
    private function minimumDeviationScore(int $remaining, array $directions): int
    {
        $divisor = 0;
        foreach ($directions as $direction) {
            $distance = $direction['destination']->distance;
            while (0 !== $distance) {
                [$divisor, $distance] = [$distance, $divisor % $distance];
            }
        }

        // The reference total is twice a multiple of this divisor.
        $remainder = intdiv($remaining, 2) % $divisor;

        return min($remainder, $divisor - $remainder - $remaining % 2);
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
}
