<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingsBySubstance;

use App\ChemicalResistance\Application\DTO\SubstanceRefDTO;
use App\Coatings\Application\DTO\Coatings\CoatingResistanceDTO;
use App\Shared\Domain\Repository\Pager;

readonly class GetCoatingsBySubstanceQueryResult
{
    /**
     * @param CoatingResistanceDTO[] $items
     * @param SubstanceRefDTO[]      $selectedSubstances резолвнутые выбранные вещества (для чипов)
     */
    public function __construct(
        public array $items,
        public Pager $pager,
        public array $selectedSubstances,
    ) {
    }
}
