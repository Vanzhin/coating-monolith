<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Note\DeleteNote;

use App\ChemicalResistance\Domain\Repository\AssessmentRepositoryInterface;
use App\ChemicalResistance\Domain\Repository\NoteRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;

final class DeleteNoteCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private NoteRepositoryInterface $notes,
        private AssessmentRepositoryInterface $assessments,
    ) {
    }

    public function __invoke(DeleteNoteCommand $c): void
    {
        $note = $this->notes->findOneById($c->id)
            ?? throw new AppException('Примечание не найдено.');

        $used = $this->assessments->countAssessmentsWithNoteId($c->id);
        if ($used > 0) {
            throw new AppException(sprintf('Примечание используется в %d оценках, удаление невозможно.', $used));
        }
        $this->notes->remove($note);
    }
}
