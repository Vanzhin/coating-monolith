<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetPagedCoatingTags;

use App\Coatings\Domain\Repository\CoatingTagsFilter;
use App\Shared\Application\Query\Query;

readonly class GetPagedCoatingTagsQuery extends Query
{
    public function __construct(public CoatingTagsFilter $filter)
    {
    }
}
