<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Waybill;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\RouteLog;

final readonly class OdometerLog
{
    public function __construct(
        public RouteLog $routeLog,
        public int $start,
        public int $end,
    ) {
        if ($start < 0 || $end < $start || $end - $start !== $routeLog->distanceInKilometers()) {
            throw new AppException('Показания одометра не соответствуют расстоянию участка.');
        }
    }
}
