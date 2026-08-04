<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatingSystems;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;

final readonly class SearchCoatingSystemsQueryResult
{
    /** @param list<CoatingSystemDTO> $items */
    public function __construct(
        public array $items,
        public int $total,
    ) {
    }
}
