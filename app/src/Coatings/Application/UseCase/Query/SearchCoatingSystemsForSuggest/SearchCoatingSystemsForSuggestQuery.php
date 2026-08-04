<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatingSystemsForSuggest;

use App\Shared\Application\Query\Query;

final readonly class SearchCoatingSystemsForSuggestQuery extends Query
{
    public function __construct(public string $q, public int $limit)
    {
    }
}
