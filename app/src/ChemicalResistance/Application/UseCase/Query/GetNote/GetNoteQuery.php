<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetNote;

use App\Shared\Application\Query\Query;

readonly class GetNoteQuery extends Query
{
    public function __construct(public string $id)
    {
    }
}
