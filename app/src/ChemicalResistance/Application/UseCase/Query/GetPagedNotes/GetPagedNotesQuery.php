<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetPagedNotes;

use App\ChemicalResistance\Domain\Repository\NotesFilter;
use App\Shared\Application\Query\Query;

readonly class GetPagedNotesQuery extends Query
{
    public function __construct(public NotesFilter $filter)
    {
    }
}
