<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetWaybillForEdit;

use App\Shared\Application\Query\Query;

final readonly class GetWaybillForEditQuery extends Query
{
    public function __construct(public string $waybillId, public bool $withPredecessor = false)
    {
    }
}
