<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Note\DeleteNote;

final readonly class DeleteNoteCommand
{
    public function __construct(public string $id)
    {
    }
}
