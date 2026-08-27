<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Assessment\DeleteAssessment;

use App\ChemicalResistance\Application\Service\AccessControl\ChemicalResistanceAccessControl;
use App\ChemicalResistance\Domain\Repository\AssessmentRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

final class DeleteAssessmentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private AssessmentRepositoryInterface $assessments,
        private ChemicalResistanceAccessControl $access,
    ) {
    }

    public function __invoke(DeleteAssessmentCommand $c): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $a = $this->assessments->findOneById($c->id)
            ?? throw new AppException('Оценка не найдена.');
        $this->assessments->remove($a);
    }
}
