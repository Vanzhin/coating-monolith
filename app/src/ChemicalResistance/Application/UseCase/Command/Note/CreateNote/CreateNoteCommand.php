<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Note\CreateNote;

final readonly class CreateNoteCommand
{
    public function __construct(public string $title, public string $description) {}
}
