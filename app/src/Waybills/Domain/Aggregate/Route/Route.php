<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Route;

use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;

final readonly class Route
{
    private int $totalDistance;

    public static function fromUnorderedTrips(Vehicle $vehicle, RouteLog ...$trips): self
    {
        if ([] === $trips) {
            return new self($vehicle);
        }

        $byId = [];
        foreach ($trips as $trip) {
            if ($trip->vehicle->getId() !== $vehicle->getId()) {
                throw new AppException('Поездка относится к другому автомобилю.');
            }
            $id = (string) $trip->id;
            if (isset($byId[$id])) {
                throw new AppException('Одна поездка не может учитываться дважды.');
            }
            $byId[$id] = $trip;
        }

        $first = null;
        $nextById = [];
        foreach ($trips as $trip) {
            $previousId = null === $trip->previous ? null : (string) $trip->previous->id;
            if (null === $previousId || !isset($byId[$previousId])) {
                if (null !== $first) {
                    throw new AppException('Поездки должны образовывать единую цепочку.');
                }
                $first = $trip;
                continue;
            }
            if (isset($nextById[$previousId]) || $byId[$previousId] !== $trip->previous) {
                throw new AppException('Поездки должны образовывать единую цепочку.');
            }
            $nextById[$previousId] = $trip;
        }

        $last = $first;
        $visited = 0;
        while (null !== $last && $visited < count($trips)) {
            ++$visited;
            $next = $nextById[(string) $last->id] ?? null;
            if (null === $next) {
                break;
            }
            $last = $next;
        }
        if (null === $first || $visited !== count($trips) || null !== ($nextById[(string) $last->id] ?? null)) {
            throw new AppException('Поездки должны образовывать единую цепочку.');
        }

        return new self($vehicle, $first, $last);
    }

    private ?RouteLog $predecessor;

    public function __construct(
        private Vehicle $vehicle,
        private ?RouteLog $first = null,
        private ?RouteLog $last = null,
        ?RouteLog $predecessor = null,
    ) {
        if ((null === $first) !== (null === $last)) {
            throw new AppException('Укажите первую и последнюю поездки маршрута.');
        }
        if (null !== $predecessor && $predecessor->vehicle->getId() !== $vehicle->getId()) {
            throw new AppException('Предыдущая поездка относится к другому автомобилю.');
        }
        if (null !== $first && null !== $predecessor && $first->previous !== $predecessor) {
            throw new AppException('Указан неверный предшественник маршрута.');
        }

        $distance = 0;
        if (null !== $first && null !== $last) {
            if ($last->vehicle->getId() !== $vehicle->getId()) {
                throw new AppException('Поездка относится к другому автомобилю.');
            }
            $seen = [];
            $foundFirst = false;
            for ($log = $last; null !== $log; $log = $log->previous) {
                $id = (string) $log->id;
                if (isset($seen[$id])) {
                    throw new AppException('Цепочка маршрута не может содержать цикл.');
                }
                $seen[$id] = true;
                if ($log->distanceInKilometers() > PHP_INT_MAX - $distance) {
                    throw new AppException('Общий пробег маршрута слишком велик.');
                }
                $distance += $log->distanceInKilometers();
                if ($log === $first) {
                    $foundFirst = true;
                    break;
                }
            }
            if (!$foundFirst) {
                throw new AppException('Первая и последняя поездки не принадлежат одной цепочке.');
            }
        }

        $this->predecessor = null === $first ? $predecessor : $first->previous;
        $this->totalDistance = $distance;
    }

    public function vehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function predecessor(): ?RouteLog
    {
        return $this->predecessor;
    }

    public function first(): ?RouteLog
    {
        return $this->first;
    }

    public function last(): ?RouteLog
    {
        return $this->last;
    }

    /** @return iterable<RouteLog> */
    public function logs(): iterable
    {
        yield from $this->logsInOrder();
    }

    public function period(): ?DateTimeInterval
    {
        if (null === $this->first || null === $this->last) {
            return null;
        }

        return new DateTimeInterval($this->first->date, $this->last->date);
    }

    public function distance(): int
    {
        return $this->totalDistance;
    }

    public function distanceAt(\DateTimeImmutable $date): int
    {
        if (null === $this->first) {
            return 0;
        }

        $distance = 0;
        $endOfDay = $date->setTime(23, 59, 59, 999999);
        for ($log = $this->last; null !== $log; $log = $log->previous) {
            if ($log->date <= $endOfDay) {
                $distance += $log->distanceInKilometers();
            }
            if ($log === $this->first) {
                break;
            }
        }

        return $distance;
    }

    public function odometerAt(\DateTimeImmutable $date, int $odometerBeforeRoute): int
    {
        if ($odometerBeforeRoute < 0) {
            throw new AppException('Показание одометра должно быть неотрицательным.');
        }
        $distance = $this->distanceAt($date);
        if ($distance > PHP_INT_MAX - $odometerBeforeRoute) {
            throw new AppException('Показание одометра слишком велико.');
        }

        return $odometerBeforeRoute + $distance;
    }

    public function locationAt(\DateTimeImmutable $date): ?Location
    {
        if (null === $this->first) {
            return null;
        }

        $endOfDay = $date->setTime(23, 59, 59, 999999);
        for ($log = $this->last; null !== $log; $log = $log->previous) {
            if ($log->date <= $endOfDay) {
                return $log->destination->location;
            }
            if ($log === $this->first) {
                $previous = $log->previous;
                if (null === $previous) {
                    return $this->vehicle->initialLocation();
                }

                return $previous->date <= $endOfDay ? $previous->destination->location : null;
            }
        }

        return null;
    }

    public function insertAfter(?RouteLog $previous, RouteLog ...$inserted): self
    {
        if ([] === $inserted) {
            return $this;
        }

        $logs = $this->logsInOrder();
        if ([] === $logs) {
            return $this->rewrite([], 0, 0, $previous, ...$inserted);
        }

        if ($previous === $this->first?->previous) {
            return $this->rewrite($logs, 0, 0, $previous, ...$inserted);
        }
        foreach ($logs as $index => $log) {
            if ($log === $previous) {
                return $this->rewrite($logs, $index + 1, 0, $previous, ...$inserted);
            }
        }

        throw new AppException('Предыдущая поездка не найдена в маршруте.');
    }

    public function replaceSegment(RouteLog $first, RouteLog $last, RouteLog ...$replacement): self
    {
        $logs = $this->logsInOrder();
        $start = array_search($first, $logs, true);
        $end = array_search($last, $logs, true);
        if (false === $start || false === $end || $end < $start) {
            throw new AppException('Указан неверный участок маршрута.');
        }

        return $this->rewrite($logs, $start, $end - $start + 1, $first->previous, ...$replacement);
    }

    /** @return list<RouteLog> */
    private function logsInOrder(): array
    {
        $logs = [];
        for ($log = $this->last; null !== $log; $log = $log->previous) {
            $logs[] = $log;
            if ($log === $this->first) {
                break;
            }
        }

        return array_reverse($logs);
    }

    /** @param list<RouteLog> $logs */
    private function rewrite(array $logs, int $offset, int $length, ?RouteLog $previous, RouteLog ...$replacement): self
    {
        $this->assertAutomaticTripsDoNotReplaceManual(array_slice($logs, $offset, $length), ...$replacement);
        $isAutomaticInsertion = 0 === $length && [] !== $replacement;
        $tail = $previous;
        foreach ($replacement as $log) {
            if ($log->vehicle->getId() !== $this->vehicle->getId() || $log->previous !== $tail) {
                throw new AppException('Новые поездки должны продолжать выбранную цепочку автомобиля.');
            }
            $isAutomaticInsertion = $isAutomaticInsertion && RouteLogOrigin::Automatic === $log->origin;
            $tail = $log;
        }

        $next = $logs[$offset + $length] ?? null;
        if (null !== $next) {
            $newDeparture = $tail?->destination->location ?? $this->vehicle->initialLocation();
            if (!$next->departure()->samePlace($newDeparture)) {
                throw new AppException('Изменение затрагивает следующую поездку. Исправьте её явно.');
            }
            if ($isAutomaticInsertion && RouteLogOrigin::Manual === $next->origin) {
                $next->assertDepartureUnchanged($newDeparture);
            }
        }

        $first = 0 === $offset ? ($replacement[0] ?? null) : $this->first;
        foreach (array_slice($logs, $offset + $length) as $log) {
            $tail = $log->withPrevious($tail);
            $first ??= $tail;
        }
        if (null === $first) {
            return new self($this->vehicle, predecessor: $previous);
        }

        return new self($this->vehicle, $first, $tail);
    }

    /** @param list<RouteLog> $replaced */
    private function assertAutomaticTripsDoNotReplaceManual(array $replaced, RouteLog ...$replacement): void
    {
        $containsManual = false;
        foreach ($replaced as $log) {
            $containsManual = $containsManual || RouteLogOrigin::Manual === $log->origin;
        }
        if (!$containsManual) {
            return;
        }
        foreach ($replacement as $log) {
            if (RouteLogOrigin::Automatic === $log->origin) {
                throw new AppException('Автоматические поездки не могут заменять введённые пользователем.');
            }
        }
    }
}
