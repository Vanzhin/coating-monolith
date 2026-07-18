<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Note\CreateNote;

use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use Symfony\Component\Uid\Uuid;

final class CreateNoteCommandHandler
{
    public function __construct(private NoteRepository $repo) {}

    public function __invoke(CreateNoteCommand $c): string
    {
        $note = new Note(Uuid::v4(), $c->title, $c->description);
        $this->repo->save($note);
        return $note->getId();
    }
}
