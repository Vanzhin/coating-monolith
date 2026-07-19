<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Note\CreateNote;

use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Repository\NoteRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Symfony\Component\Uid\Uuid;

final class CreateNoteCommandHandler implements CommandHandlerInterface
{
    public function __construct(private NoteRepositoryInterface $repo)
    {
    }

    public function __invoke(CreateNoteCommand $c): string
    {
        $note = new Note(Uuid::v4(), $c->title, $c->description);
        $this->repo->add($note);

        return $note->getId();
    }
}
