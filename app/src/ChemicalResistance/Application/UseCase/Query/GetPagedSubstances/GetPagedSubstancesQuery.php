<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetPagedSubstances;

use App\ChemicalResistance\Domain\Repository\SubstancesFilter;
use App\Shared\Application\Query\Query;

readonly class GetPagedSubstancesQuery extends Query
{
    public function __construct(public SubstancesFilter $filter)
    {
    }
}
