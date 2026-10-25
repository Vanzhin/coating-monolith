<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetSubstancesByIds;

use App\Shared\Application\Query\Query;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

readonly class GetSubstancesByIdsQuery extends Query
{
    public function __construct(public StringCollection $ids)
    {
    }
}
