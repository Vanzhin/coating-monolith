<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchSystemsAndCoatings;

use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;

final readonly class SearchSystemsAndCoatingsQueryResult
{
    /** @param list<string> $uncheckedCoatingConditions */
    public function __construct(
        public SearchCoatingSystemsQueryResult $systems,
        public ?GetPagedCoatingsQueryResult $coatings,
        public array $uncheckedCoatingConditions,
    ) {
    }
}
