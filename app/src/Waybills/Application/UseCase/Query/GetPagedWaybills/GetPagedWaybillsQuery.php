<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetPagedWaybills;

use App\Shared\Application\Query\Query;
use App\Waybills\Domain\Repository\WaybillFilter;

final readonly class GetPagedWaybillsQuery extends Query
{
    public function __construct(public WaybillFilter $filter)
    {
    }
}
