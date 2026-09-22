<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingCalcContext;

use App\Coatings\Application\DTO\Coatings\CoatingSuggestDTO;

readonly class GetCoatingCalcContextQueryResult
{
    public function __construct(public ?CoatingSuggestDTO $coating)
    {
    }
}
