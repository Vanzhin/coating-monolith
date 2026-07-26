<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\ListCoatingSystems;

use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Shared\Application\Query\Query;

readonly class ListCoatingSystemsQuery extends Query
{
    public function __construct(
        public CoatingSystemsFilter $filter,
        public int $page,
        public int $perPage,
    ) {
    }
}
