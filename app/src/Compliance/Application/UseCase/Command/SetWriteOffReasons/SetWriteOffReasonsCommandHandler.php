<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SetWriteOffReasons;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/** Сохранить причины списания по позициям корзины (черновик акта). */
final readonly class SetWriteOffReasonsCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
    ) {
    }

    public function __invoke(SetWriteOffReasonsCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан.');

        $map = [];
        foreach ($command->reasons as $portionId => $value) {
            $reason = WriteOffReason::tryFrom(trim((string) $value));
            if (null !== $reason) {
                $map[(string) $portionId] = $reason;
            }
        }

        $profileCompliance->applyWriteOffReasons($command->writeOffActId, $map, new \DateTimeImmutable());
        $this->repository->add($profileCompliance);
    }
}
