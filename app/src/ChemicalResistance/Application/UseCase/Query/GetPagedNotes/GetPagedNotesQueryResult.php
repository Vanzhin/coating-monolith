<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetPagedNotes;

use App\ChemicalResistance\Application\DTO\NoteDTO;
use App\Shared\Domain\Repository\Pager;

class GetPagedNotesQueryResult
{
    /**
     * @param NoteDTO[] $notes
     */
    public function __construct(
        public readonly array $notes,
        public readonly Pager $pager,
    ) {
    }
}
