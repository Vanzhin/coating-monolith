<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetVehicle;

use App\Shared\Application\Query\Query;

final readonly class GetVehicleQuery extends Query
{
    public function __construct(public string $id, public bool $forWaybillCreation = false)
    {
    }
}
