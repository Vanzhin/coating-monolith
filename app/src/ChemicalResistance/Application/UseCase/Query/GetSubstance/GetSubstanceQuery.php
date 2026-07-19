<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetSubstance;

use App\Shared\Application\Query\Query;

readonly class GetSubstanceQuery extends Query
{
    public function __construct(public string $id) {}
}
