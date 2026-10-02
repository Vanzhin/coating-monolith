<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\WriteOffPositions;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

/**
 * «Списать» на странице акта получения: кладёт материальные позиции в корзину (черновик акта списания). Без
 * эффекта — факты не гасятся, пересчёта нет; эффект наступает при оформлении акта ({@see \App\Compliance\Application\UseCase\Command\SignWriteOffAct\SignWriteOffActCommandHandler}).
 */
final readonly class WriteOffPositionsCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
    ) {
    }

    public function __invoke(WriteOffPositionsCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан.');

        $keys = array_values(array_filter(
            array_map(static fn ($k): string => trim((string) $k), $command->obligationKeys),
            static fn (string $k): bool => '' !== $k,
        ));
        if ([] === $keys) {
            throw new AppException('Выберите хотя бы одну позицию для списания.');
        }

        $profileCompliance->writeOff(Uuid::v7(), $command->requirementId, $keys, new \DateTimeImmutable());
        $this->repository->add($profileCompliance);
    }
}
