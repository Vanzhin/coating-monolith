<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetPagedCoatings;

use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Shared\Application\Query\Query;

readonly class GetPagedCoatingsQuery extends Query
{
    public function __construct(public CoatingsFilter $filter)
    {
    }
}
