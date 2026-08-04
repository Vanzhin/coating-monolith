<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetPagedTags;

use App\Coatings\Domain\Repository\TagsFilter;
use App\Shared\Application\Query\Query;

readonly class GetPagedTagsQuery extends Query
{
    public function __construct(public TagsFilter $filter)
    {
    }
}
