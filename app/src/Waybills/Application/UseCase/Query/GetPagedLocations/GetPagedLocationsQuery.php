<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetPagedLocations;

use App\Shared\Application\Query\Query;
use App\Waybills\Domain\Repository\LocationFilter;

final readonly class GetPagedLocationsQuery extends Query
{
    public function __construct(public LocationFilter $filter)
    {
    }
}
