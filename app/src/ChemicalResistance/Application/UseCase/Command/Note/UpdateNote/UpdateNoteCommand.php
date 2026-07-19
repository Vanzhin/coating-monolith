<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Note\UpdateNote;

final readonly class UpdateNoteCommand
{
    public function __construct(public string $id, public string $title, public string $description)
    {
    }
}
