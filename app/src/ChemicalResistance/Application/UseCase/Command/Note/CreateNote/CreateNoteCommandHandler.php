<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Note\CreateNote;

use App\ChemicalResistance\Application\Service\AccessControl\ChemicalResistanceAccessControl;
use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Repository\NoteRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

final class CreateNoteCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private NoteRepositoryInterface $repo,
        private ChemicalResistanceAccessControl $access,
    ) {
    }

    public function __invoke(CreateNoteCommand $c): string
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $note = new Note(Uuid::v4(), $c->title, $c->description);
        $this->repo->add($note);

        return $note->getId();
    }
}
