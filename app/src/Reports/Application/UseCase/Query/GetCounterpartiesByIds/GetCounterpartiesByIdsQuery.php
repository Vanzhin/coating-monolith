<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetCounterpartiesByIds;

use App\Shared\Application\Query\Query;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

readonly class GetCounterpartiesByIdsQuery extends Query
{
    public function __construct(public StringCollection $ids)
    {
    }
}
