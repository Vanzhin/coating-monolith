<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoating;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;

readonly class GetCoatingQueryResult
{
    public function __construct(public ?CoatingDTO $coatingDTO)
    {
    }
}
