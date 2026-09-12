<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatings;

use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Shared\Application\Query\Query;

/**
 * Лёгкий постраничный поиск покрытий для typeahead. Один-в-один с GetPagedCoatingsQuery
 * (принимает CoatingsFilter — search + pager). Разница ТОЛЬКО в весе результата: лёгкие
 * CoatingSuggestDTO вместо полного CoatingDTO и без обогащения химстойкостью.
 */
readonly class SearchCoatingsQuery extends Query
{
    public function __construct(public CoatingsFilter $filter)
    {
    }
}
