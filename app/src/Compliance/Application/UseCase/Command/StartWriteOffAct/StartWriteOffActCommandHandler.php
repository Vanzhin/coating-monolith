<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\StartWriteOffAct;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

/** Открывает/создаёт черновик акта списания по требованию; id акта контроллер берёт через openWriteOffDraftFor. */
final readonly class StartWriteOffActCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
    ) {
    }

    public function __invoke(StartWriteOffActCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан.');

        $profileCompliance->startOrGetWriteOffDraft(Uuid::v7(), $command->requirementId, new \DateTimeImmutable());
        $this->repository->add($profileCompliance);
    }
}
