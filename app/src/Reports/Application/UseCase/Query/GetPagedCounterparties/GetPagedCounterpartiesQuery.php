<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetPagedCounterparties;

use App\Reports\Domain\Repository\CounterpartiesFilter;
use App\Shared\Application\Query\Query;

final readonly class GetPagedCounterpartiesQuery extends Query
{
    public function __construct(public CounterpartiesFilter $filter)
    {
    }
}
