<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetPagedWriteOffActs;

use App\Compliance\Domain\Repository\WriteOffActFilter;
use App\Shared\Application\Query\Query;

readonly class GetPagedWriteOffActsQuery extends Query
{
    public function __construct(public WriteOffActFilter $filter)
    {
    }
}
