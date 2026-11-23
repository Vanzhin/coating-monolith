<?php

declare(strict_types=1);

namespace App\Waybills\Application\Service;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Application\DTO\TripInput;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use Symfony\Component\Uid\Uuid;

final class WaybillRouteBuilder
{
    public function build(Waybill $waybill, ?RouteLog $previous, bool $routePresent, TripInput ...$inputs): ?Route
    {
        if (!$routePresent && [] !== $inputs) {
            throw new AppException('Поездки не могут быть заданы без маршрута.');
        }
        $existing = [];
        foreach ($waybill->trips() as $trip) {
            $existing[(string) $trip->id] = $trip;
        }
        $inputs = $this->convertEditedAutomaticPairs($existing, $inputs);

        return $this->buildLogs($waybill, $existing, $previous, $routePresent, ...$inputs);
    }

    public function buildFixed(Waybill $waybill, ?RouteLog $previous, bool $routePresent, TripInput ...$inputs): ?Route
    {
        if (!$routePresent && [] !== $inputs) {
            throw new AppException('Поездки не могут быть заданы без маршрута.');
        }
        $existing = [];
        foreach ($waybill->trips() as $trip) {
            $existing[(string) $trip->id] = $trip;
        }
        $inputs = $this->convertEditedAutomaticPairs($existing, $inputs);
        $fixed = array_values(array_filter(
            $inputs,
            static fn (TripInput $input): bool => RouteLogOrigin::Manual === $input->origin,
        ));

        return $this->buildLogs($waybill, $existing, $previous, $routePresent, ...$fixed);
    }

    /**
     * @param array<string, RouteLog> $existing
     */
    private function buildLogs(Waybill $waybill, array $existing, ?RouteLog $previous, bool $routePresent, TripInput ...$inputs): ?Route
    {
        $first = null;
        $last = null;
        foreach ($inputs as $input) {
            $original = null === $input->id ? null : ($existing[(string) $input->id] ?? null);
            if (null !== $input->id && null === $original && RouteLogOrigin::Automatic !== $input->origin && null === $input->originalDestination) {
                throw new AppException('Поездка не принадлежит этому черновику.');
            }
            $origin = $input->origin;
            if (null !== $original && (RouteLogOrigin::Manual === $original->origin || RouteLogOrigin::Manual === $input->origin)) {
                $origin = RouteLogOrigin::Manual;
            }
            $last = new RouteLog($input->id ?? Uuid::v7(), $waybill->vehicle(), $previous, $input->date, $input->destination, $origin);
            $first ??= $last;
            $previous = $last;
        }

        return $routePresent ? new Route($waybill->vehicle(), $first, $last) : null;
    }

    /**
     * @param array<string, RouteLog> $existing
     * @param list<TripInput>         $inputs
     *
     * @return list<TripInput>
     */
    private function convertEditedAutomaticPairs(array $existing, array $inputs): array
    {
        $pairCounts = [];
        $changedPairs = [];
        foreach ($inputs as $index => $input) {
            if (RouteLogOrigin::Automatic !== $input->origin) {
                continue;
            }
            $pairKey = $this->pairKey($input, $index);
            $pairCounts[$pairKey] = ($pairCounts[$pairKey] ?? 0) + 1;
            $original = null === $input->id ? null : ($existing[(string) $input->id] ?? null);
            if ($this->wasChanged($input, $original)) {
                $changedPairs[$pairKey] = true;
            }
        }

        foreach ($inputs as $index => $input) {
            if (RouteLogOrigin::Automatic !== $input->origin) {
                continue;
            }
            $pairKey = $this->pairKey($input, $index);
            if (isset($changedPairs[$pairKey]) || 1 === ($pairCounts[$pairKey] ?? 0)) {
                $inputs[$index] = new TripInput(
                    $input->id,
                    $input->date,
                    $input->destination,
                    RouteLogOrigin::Manual,
                    $input->pairId,
                    $input->originalDate,
                    $input->originalDestination,
                );
            }
        }

        return $inputs;
    }

    private function pairKey(TripInput $input, int $index): string
    {
        return null === $input->pairId ? 'single-'.$index : (string) $input->pairId;
    }

    private function wasChanged(TripInput $input, ?RouteLog $original): bool
    {
        if (null !== $original) {
            return $original->date != $input->date || $original->destination != $input->destination;
        }
        if (null === $input->originalDate || null === $input->originalDestination) {
            throw new AppException('Исходные данные автоматической поездки не переданы.');
        }

        return $input->originalDate != $input->date || $input->originalDestination != $input->destination;
    }
}
