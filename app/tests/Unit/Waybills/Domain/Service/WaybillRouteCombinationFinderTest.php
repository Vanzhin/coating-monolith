<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Service;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Service\RouteFillOption;
use App\Waybills\Domain\Service\RouteFillSolution;
use App\Waybills\Domain\Service\WaybillRouteCombinationFinder;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class WaybillRouteCombinationFinderTest extends TestCase
{
    public function test_realistic_month_is_fast_and_exact(): void
    {
        $destinations = $this->destinations([15, 20, 25, 30, 35, 40, 45]);
        $start = hrtime(true);

        $solution = (new WaybillRouteCombinationFinder())->find(1000, 22, ...$destinations);
        $elapsed = (hrtime(true) - $start) / 1e9;

        self::assertLessThan(2.0, $elapsed);
        self::assertSame(1000, $this->distance($solution));
        self::assertSame(7, $solution->coverage());
        self::assertLessThanOrEqual(22, $solution->pairCount());
        $this->assertOptionsValid($solution);
    }

    public function test_small_cases_match_exhaustive_search(): void
    {
        $cases = [
            [21, 2, [10]],
            [31, 2, [10, 15]],
            [45, 3, [10, 20]],
            [60, 4, [10, 20, 30]],
            [17, 2, [10]],
            [100, 4, [10, 20]],
        ];
        foreach ($cases as [$remaining, $days, $distances]) {
            $destinations = $this->destinations($distances);
            $actual = (new WaybillRouteCombinationFinder())->find($remaining, $days, ...$destinations);
            $expected = $this->bruteForce($remaining, $days, ...$destinations);
            $message = sprintf('Остаток %d, дней %d, направления %s', $remaining, $days, implode(',', $distances));

            self::assertSame($expected->coverage(), $actual->coverage(), $message.' — покрытие');
            self::assertSame($expected->deviation(), $actual->deviation(), $message.' — отклонение');
            self::assertSame($expected->pairCount(), $actual->pairCount(), $message.' — число пар');
            self::assertSame($expected->imbalance(), $actual->imbalance(), $message.' — дисбаланс');
            self::assertSame($remaining, $this->distance($actual), $message.' — сумма');
            $this->assertOptionsValid($actual);
        }
    }

    public function test_impossible_distance_and_insufficient_days_are_rejected(): void
    {
        $finder = new WaybillRouteCombinationFinder();
        foreach ([[1, 1, [10], 'из настроенных направлений'], [100, 2, [10], 'за доступные рабочие дни']] as [$remaining, $days, $distances, $message]) {
            try {
                $finder->find($remaining, $days, ...$this->destinations($distances));
                self::fail('Невозможный набор должен быть отклонён.');
            } catch (AppException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function test_long_period_with_equal_distances_is_fast_and_optimal(): void
    {
        $destinations = $this->distinctDestinations([10, 10, 10, 10, 10, 10, 10]);
        $start = hrtime(true);

        $solution = (new WaybillRouteCombinationFinder())->find(1650, 55, ...$destinations);
        $elapsed = (hrtime(true) - $start) / 1e9;

        self::assertLessThan(10.0, $elapsed, 'Подбор для длинного периода занял слишком много времени.');
        self::assertSame([-7, 550, 55, 1], $this->independentObjectiveOf($solution, 1650, ...$destinations));
        self::assertSame(7, $solution->coverage());
        self::assertSame(550, $solution->deviation());
        self::assertSame(55, $solution->pairCount());
        self::assertSame(1, $solution->imbalance());

        $uses = [];
        foreach ($solution->options() as $option) {
            self::assertSame(15, $option->outwardDistance);
            self::assertSame(15, $option->returnDistance);
            $location = $option->destination->location->name;
            $uses[$location] = ($uses[$location] ?? 0) + 1;
        }
        $expectedLocations = array_map(static fn (Destination $destination): string => $destination->location->name, $destinations);
        $actualLocations = array_keys($uses);
        sort($expectedLocations);
        sort($actualLocations);
        self::assertSame($expectedLocations, $actualLocations);
        $counts = array_values($uses);
        sort($counts);
        self::assertSame([7, 8, 8, 8, 8, 8, 8], $counts);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_long_period_with_heterogeneous_distances_is_fast_and_optimal(): void
    {
        $destinations = $this->distinctDestinations([54, 16, 72, 24, 20, 70, 66, 32, 40]);
        mt_srand(0);
        $start = hrtime(true);

        $solution = (new WaybillRouteCombinationFinder())->find(3162, 55, ...$destinations);
        $elapsed = (hrtime(true) - $start) / 1e9;

        self::assertLessThan(10.0, $elapsed, 'Подбор для длинного периода занял слишком много времени.');
        self::assertSame([-9, 2, 26, 7], $this->independentObjectiveOf($solution, 3162, ...$destinations));
        self::assertSame(9, $solution->coverage());
        self::assertSame(2, $solution->deviation());
        self::assertSame(26, $solution->pairCount());
        self::assertSame(7, $solution->imbalance());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_equal_distance_distributions_can_vary_with_seed(): void
    {
        $destinations = $this->distinctDestinations([10]);
        $expected = $this->independentExhaustiveObjective(45, 2, [10]);
        self::assertSame([-1, 5, 2, 0], $expected);
        $distributions = [];

        // shuffle() и mt_rand() используют общий генератор, управляемый mt_srand().
        for ($seed = 0; $seed < 64; ++$seed) {
            mt_srand($seed);
            $solution = (new WaybillRouteCombinationFinder())->find(45, 2, ...$destinations);
            self::assertSame($expected, $this->independentObjectiveOf($solution, 45, ...$destinations), 'Seed '.$seed);

            $pairDistances = array_map(static fn (RouteFillOption $option): int => $option->outwardDistance + $option->returnDistance, $solution->options());
            sort($pairDistances);
            $distributions[implode(',', $pairDistances)] = true;
        }

        self::assertGreaterThan(1, count($distributions), 'Ожидаются разные распределения расстояний, а не только другой порядок пар.');
    }

    public function test_heterogeneous_cases_match_independent_exhaustive_search(): void
    {
        $cases = [
            // Охват важнее отклонения; отклонение важнее числа пар.
            [40, 2, [10, 20], [-2, 20, 2, 0]],
            [80, 3, [10, 20], [-2, 0, 3, 1]],
            // При одинаковом отклонении выбираются меньше пар, затем равномерность.
            [30, 2, [10], [-1, 10, 1, 0]],
            [10, 5, [1, 1, 1], [-3, 0, 5, 1]],
            [31, 2, [7, 10, 15], null],
            [47, 2, [10, 20, 30], null],
            [59, 3, [7, 11, 18], null],
            [63, 1, [30, 31, 100, 101], null],
            [401, 2, [100, 101], null],
            [33, 1, [10, 30], null],
            [4, 2, [10, 20], null],
        ];

        foreach ($cases as [$remaining, $days, $distances, $knownObjective]) {
            $message = sprintf('Остаток %d, дней %d, направления %s', $remaining, $days, implode(',', $distances));
            $expected = $this->independentExhaustiveObjective($remaining, $days, $distances);
            if (null !== $knownObjective) {
                self::assertSame($knownObjective, $expected, $message.' — независимый перебор');
            }
            $destinations = $this->distinctDestinations($distances);

            try {
                $solution = (new WaybillRouteCombinationFinder())->find($remaining, $days, ...$destinations);
            } catch (AppException $exception) {
                self::assertNull($expected, $message.' — неожиданное отклонение: '.$exception->getMessage());
                continue;
            }

            self::assertNotNull($expected, $message.' — невозможный набор должен быть отклонён');
            self::assertSame($expected, $this->independentObjectiveOf($solution, $remaining, ...$destinations), $message);
        }
    }

    /**
     * @param list<int> $distances
     *
     * @return list<Destination>
     */
    private function distinctDestinations(array $distances): array
    {
        return array_map(
            static fn (int $index, int $distance): Destination => new Destination(new Location(street: 'Направление '.$index), $distance),
            array_keys($distances),
            $distances,
        );
    }

    /**
     * Перебирает пары плеч без использования классов решения и их методов оценки.
     * Первое значение оценки отрицательное, чтобы все четыре критерия минимизировались.
     *
     * @param list<int> $distances
     *
     * @return array{int, int, int, int}|null
     */
    private function independentExhaustiveObjective(int $remaining, int $days, array $distances): ?array
    {
        $options = [];
        foreach ($distances as $direction => $reference) {
            [$minimum, $maximum] = $this->range($reference);
            for ($outward = $minimum; $outward <= $maximum; ++$outward) {
                foreach ([$outward, $outward + 1] as $return) {
                    if ($return > $maximum || $outward + $return > $remaining) {
                        continue;
                    }
                    $options[] = [
                        'direction' => $direction,
                        'distance' => $outward + $return,
                        'deviation' => abs($outward - $reference) + abs($return - $reference),
                    ];
                }
            }
        }

        $best = null;
        $uses = array_fill(0, count($distances), 0);
        $search = function (int $firstOption, int $sum, int $daysLeft, int $deviation) use (&$search, &$best, &$uses, $options, $remaining, $days): void {
            if ($sum === $remaining) {
                $used = array_values(array_filter($uses, static fn (int $count): bool => $count > 0));
                $candidate = [-count($used), $deviation, $days - $daysLeft, max($used) - min($used)];
                if (null === $best || $candidate < $best) {
                    $best = $candidate;
                }

                return;
            }
            if (0 === $daysLeft) {
                return;
            }
            for ($index = $firstOption; $index < count($options); ++$index) {
                $option = $options[$index];
                if ($sum + $option['distance'] > $remaining) {
                    continue;
                }
                ++$uses[$option['direction']];
                $search($index, $sum + $option['distance'], $daysLeft - 1, $deviation + $option['deviation']);
                --$uses[$option['direction']];
            }
        };
        $search(0, 0, $days, 0);

        return $best;
    }

    /** @return array{int, int, int, int} */
    private function independentObjectiveOf(RouteFillSolution $solution, int $remaining, Destination ...$destinations): array
    {
        $this->assertOptionsValid($solution);
        $uses = [];
        $distance = 0;
        $deviation = 0;
        foreach ($solution->options() as $option) {
            self::assertArrayHasKey($option->direction, $destinations);
            $destination = $destinations[$option->direction];
            self::assertEquals($destination, $option->destination);
            $uses[$option->direction] = ($uses[$option->direction] ?? 0) + 1;
            $distance += $option->outwardDistance + $option->returnDistance;
            $deviation += abs($option->outwardDistance - $destination->distance) + abs($option->returnDistance - $destination->distance);
        }
        self::assertSame($remaining, $distance);
        self::assertNotEmpty($uses);

        return [-count($uses), $deviation, count($solution->options()), max($uses) - min($uses)];
    }

    /** @param list<int> $distances
     *
     * @return list<Destination>
     */
    private function destinations(array $distances): array
    {
        return array_map(static fn (int $distance): Destination => new Destination(new Location((string) $distance), $distance), $distances);
    }

    private function bruteForce(int $remaining, int $availableDayCount, Destination ...$destinations): RouteFillSolution
    {
        $options = [];
        $floorHalfRemaining = intdiv($remaining, 2);
        foreach ($destinations as $direction => $destination) {
            [$minimumLeg, $maximumLeg] = $this->range($destination->distance);
            if ($minimumLeg > $floorHalfRemaining) {
                continue;
            }
            $maximumTotal = $maximumLeg > $floorHalfRemaining ? $remaining : 2 * $maximumLeg;
            for ($total = 2 * $minimumLeg; $total <= $maximumTotal; ++$total) {
                $outward = intdiv($total, 2);
                $options[] = new RouteFillOption($direction, $destination, $outward, $total - $outward);
            }
        }

        $best = null;
        $search = function (RouteFillSolution $solution, int $sum, int $daysLeft) use (&$search, &$best, $options, $remaining): void {
            if ($sum === $remaining && (null === $best || $this->isBetter($solution, $best))) {
                $best = $solution;
            }
            if (0 === $daysLeft) {
                return;
            }
            foreach ($options as $option) {
                if ($sum + $option->distance() > $remaining) {
                    continue;
                }
                $search($solution->withOption($option), $sum + $option->distance(), $daysLeft - 1);
            }
        };
        $search(RouteFillSolution::empty(), 0, $availableDayCount);

        self::assertNotNull($best);

        return $best;
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

    private function assertOptionsValid(RouteFillSolution $solution): void
    {
        foreach ($solution->options() as $option) {
            [$minimum, $maximum] = $this->range($option->destination->distance);
            self::assertGreaterThanOrEqual($minimum, $option->outwardDistance);
            self::assertGreaterThanOrEqual($minimum, $option->returnDistance);
            self::assertLessThanOrEqual($maximum, $option->outwardDistance);
            self::assertLessThanOrEqual($maximum, $option->returnDistance);
            self::assertSame(intdiv($option->distance(), 2), $option->outwardDistance);
        }
    }

    /** @return array{int, int} */
    private function range(int $distance): array
    {
        if ($distance <= 30) {
            return [max(1, $distance - 5), $distance + 5];
        }
        if ($distance <= 100) {
            $deviation = intdiv($distance, 10);

            return [$distance - $deviation, $distance + $deviation];
        }
        $deviation = intdiv($distance, 20);

        return [$distance - $deviation, $distance + $deviation];
    }

    private function distance(RouteFillSolution $solution): int
    {
        return array_sum(array_map(static fn (RouteFillOption $option): int => $option->distance(), $solution->options()));
    }
}
