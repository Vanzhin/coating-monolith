<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\CancelWriteOffItem;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/** Откат позиции из корзины акта списания. */
final readonly class CancelWriteOffItemCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
    ) {
    }

    public function __invoke(CancelWriteOffItemCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан.');

        $profileCompliance->cancelWriteOffItem($command->writeOffActId, $command->recordId, new \DateTimeImmutable());
        $this->repository->add($profileCompliance);
    }
}
