<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SuggestCoatingSystems;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemTitleDTO;

final readonly class SuggestCoatingSystemsQueryResult
{
    /** @param list<CoatingSystemTitleDTO> $items */
    public function __construct(public array $items)
    {
    }
}
