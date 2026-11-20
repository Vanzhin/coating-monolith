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
        $first = null;
        $last = null;
        foreach ($inputs as $input) {
            $original = null === $input->id ? null : ($existing[(string) $input->id] ?? null);
            if (null !== $input->id && null === $original) {
                throw new AppException('Поездка не принадлежит этому черновику.');
            }
            $origin = null === $original ? RouteLogOrigin::Manual : $original->origin;
            $last = new RouteLog($input->id ?? Uuid::v7(), $waybill->vehicle(), $previous, $input->date, $input->destination, $origin);
            $first ??= $last;
            $previous = $last;
        }

        return $routePresent ? new Route($waybill->vehicle(), $first, $last) : null;
    }
}
