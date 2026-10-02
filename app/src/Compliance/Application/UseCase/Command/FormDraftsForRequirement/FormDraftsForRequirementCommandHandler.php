<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\FormDraftsForRequirement;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Application\Service\DraftFormationService;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;

final readonly class FormDraftsForRequirementCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private DraftFormationService $formation,
    ) {
    }

    public function __invoke(FormDraftsForRequirementCommand $command): int
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        return $this->formation->formForRequirement($command->requirementId, new \DateTimeImmutable());
    }
}
