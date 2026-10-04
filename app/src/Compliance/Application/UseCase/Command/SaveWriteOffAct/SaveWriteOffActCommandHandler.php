<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SaveWriteOffAct;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\ValueObject\Commission;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/** Сохранить состав черновика акта списания (количества + причины по позициям, set-семантика). */
final readonly class SaveWriteOffActCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
    ) {
    }

    public function __invoke(SaveWriteOffActCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан.');

        $lines = [];
        foreach ($command->lines as $line) {
            $recordId = trim((string) ($line['recordId'] ?? ''));
            $quantity = (float) ($line['quantity'] ?? 0.0);
            if ('' === $recordId || $quantity <= 0.0) {
                continue;
            }
            $lines[] = [
                'recordId' => $recordId,
                'quantity' => $quantity,
                'reason' => WriteOffReason::tryFrom(trim((string) ($line['reason'] ?? ''))),
            ];
        }

        $actDate = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($command->actDate)) ?: null;
        $orderDate = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($command->orderDate)) ?: null;
        $profileCompliance->saveWriteOffAct(
            $command->writeOffActId, $lines, new \DateTimeImmutable(),
            $command->actNumber, $actDate, Commission::fromRows($command->members),
            $command->orderNumber, $orderDate, $command->representativePosition, $command->representativeFio,
        );
        $this->repository->add($profileCompliance);
    }
}
