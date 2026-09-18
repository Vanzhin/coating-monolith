<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\AllCoatingsForSuggest;

use App\Coatings\Application\DTO\Coatings\CoatingSuggestDTO;

readonly class AllCoatingsForSuggestQueryResult
{
    /**
     * @param list<CoatingSuggestDTO> $coatings
     */
    public function __construct(public array $coatings)
    {
    }
}
