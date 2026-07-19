<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetNote;

use App\ChemicalResistance\Application\DTO\NoteDTO;

class GetNoteQueryResult
{
    public function __construct(public readonly ?NoteDTO $note) {}
}
