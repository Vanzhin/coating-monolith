<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetPagedSubstances;

use App\ChemicalResistance\Application\DTO\SubstanceDTO;
use App\Shared\Domain\Repository\Pager;

class GetPagedSubstancesQueryResult
{
    /**
     * @param SubstanceDTO[] $substances
     */
    public function __construct(
        public readonly array $substances,
        public readonly Pager $pager,
    ) {
    }
}
