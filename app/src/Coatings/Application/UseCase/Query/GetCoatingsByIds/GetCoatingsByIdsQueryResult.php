<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingsByIds;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;

readonly class GetCoatingsByIdsQueryResult
{
    /** @param list<CoatingDTO> $coatings */
    public function __construct(public array $coatings)
    {
    }
}
