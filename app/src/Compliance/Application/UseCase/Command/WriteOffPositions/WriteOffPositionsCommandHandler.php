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
 * «Списать» на странице акта получения: кладёт порции фактов в корзину (черновик акта списания). Без
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

        $portions = $this->normalizePortions($command->portions);
        if ([] === $portions) {
            throw new AppException('Выберите, что списать.');
        }

        $profileCompliance->writeOff(Uuid::v7(), $command->requirementId, $portions, new \DateTimeImmutable());
        $this->repository->add($profileCompliance);
    }

    /**
     * @param list<array{recordId: string, quantity: float}> $portions
     *
     * @return list<array{recordId: string, quantity: float}>
     */
    private function normalizePortions(array $portions): array
    {
        $normalized = [];
        foreach ($portions as $portion) {
            $recordId = trim((string) ($portion['recordId'] ?? ''));
            $quantity = (float) ($portion['quantity'] ?? 0.0);
            if ('' === $recordId || $quantity <= 0.0) {
                continue;
            }
            $normalized[] = ['recordId' => $recordId, 'quantity' => $quantity];
        }

        return $normalized;
    }
}
