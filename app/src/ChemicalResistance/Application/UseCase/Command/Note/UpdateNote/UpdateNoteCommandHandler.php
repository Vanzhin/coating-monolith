<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Note\UpdateNote;

use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final class UpdateNoteCommandHandler
{
    public function __construct(private NoteRepository $repo) {}

    public function __invoke(UpdateNoteCommand $c): void
    {
        $note = $this->repo->find(Uuid::fromString($c->id))
            ?? throw new AppException('Примечание не найдено.');
        $note->setTitle($c->title);
        $note->setDescription($c->description);
        $this->repo->save($note);
    }
}
