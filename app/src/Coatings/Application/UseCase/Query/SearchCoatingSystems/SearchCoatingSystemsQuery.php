<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatingSystems;

use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Shared\Application\Query\Query;

final readonly class SearchCoatingSystemsQuery extends Query
{
    public function __construct(public CoatingSystemsFilter $filter)
    {
    }
}
