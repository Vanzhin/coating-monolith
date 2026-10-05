<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\DeleteDraft;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Event\DraftDeleted;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

final readonly class DeleteDraftCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(DeleteDraftCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан.');

        $requirementId = $profileCompliance->deleteDraft($command->documentId);
        $this->repository->add($profileCompliance);

        // Пересчёт проекции профиля по требованию + черновик на оставшийся дефицит — в воркере (async),
        // как у смены нормы/профиля и оформления списания. Пользователь не ждёт пересчёт.
        if (null !== $requirementId) {
            $this->eventBus->execute(new DraftDeleted($command->profileId, $requirementId));
        }
    }
}
