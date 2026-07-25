<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Note\CreateNote;

use App\Shared\Application\Command\CommandInterface;

final readonly class CreateNoteCommand implements CommandInterface
{
    public function __construct(public string $title, public string $description)
    {
    }
}
