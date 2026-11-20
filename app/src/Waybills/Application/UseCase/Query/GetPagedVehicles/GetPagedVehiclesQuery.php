<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetPagedVehicles;

use App\Shared\Application\Query\Query;
use App\Waybills\Domain\Repository\VehicleFilter;

final readonly class GetPagedVehiclesQuery extends Query
{
    public function __construct(public VehicleFilter $filter, public bool $currentDriverOnly = false)
    {
    }
}
