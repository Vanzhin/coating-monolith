<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetWaybillRenderData;

use App\Shared\Application\Query\Query;

final readonly class GetWaybillRenderDataQuery extends Query
{
    public function __construct(public string $waybillId)
    {
    }
}
