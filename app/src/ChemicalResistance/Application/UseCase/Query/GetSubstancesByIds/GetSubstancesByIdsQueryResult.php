<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetSubstancesByIds;

use App\ChemicalResistance\Application\DTO\SubstanceRefDTO;

readonly class GetSubstancesByIdsQueryResult
{
    /** @param list<SubstanceRefDTO> $substances */
    public function __construct(public array $substances)
    {
    }
}
