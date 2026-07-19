<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetSubstance;

use App\ChemicalResistance\Application\DTO\SubstanceDTO;

class GetSubstanceQueryResult
{
    public function __construct(public readonly ?SubstanceDTO $substance)
    {
    }
}
