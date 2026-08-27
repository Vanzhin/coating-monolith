<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingSystemsByIds;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemTitleDTO;

readonly class GetCoatingSystemsByIdsQueryResult
{
    /** @param list<CoatingSystemTitleDTO> $systems */
    public function __construct(public array $systems)
    {
    }
}
