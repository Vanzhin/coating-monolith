<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchSystemsAndCoatings;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Shared\Application\Query\Query;

final readonly class SearchSystemsAndCoatingsQuery extends Query
{
    public function __construct(
        public CoatingSystemsFilterInput $input,
        public int $coatingsPage = 1,
    ) {
    }
}
