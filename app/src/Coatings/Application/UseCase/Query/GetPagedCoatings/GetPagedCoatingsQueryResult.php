<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetPagedCoatings;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Shared\Domain\Repository\Pager;

readonly class GetPagedCoatingsQueryResult
{
    /**
     * @param CoatingDTO[] $coatings
     * @param Pager $pager
     */
    public function __construct(public array $coatings, public Pager $pager)
    {
    }
}
