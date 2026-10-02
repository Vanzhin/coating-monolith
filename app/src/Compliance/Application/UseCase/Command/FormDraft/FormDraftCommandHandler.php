<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\FormDraft;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Application\Service\DraftFormationService;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

final readonly class FormDraftCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
        private DraftFormationService $formation,
    ) {
    }

    public function __invoke(FormDraftCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан — сначала сформируйте учёт.');

        if ($this->formation->formForProfileRequirement($profileCompliance, $command->requirementId, new \DateTimeImmutable())) {
            $this->repository->add($profileCompliance);
        }
    }
}
