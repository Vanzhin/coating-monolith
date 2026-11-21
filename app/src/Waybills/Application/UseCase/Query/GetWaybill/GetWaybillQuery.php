<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetWaybill;

use App\Shared\Application\Query\Query;

final readonly class GetWaybillQuery extends Query
{
    public function __construct(public string $waybillId)
    {
    }
}
