<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SaveWriteOffAct;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\ValueObject\Commission;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Сохранить состав черновика акта списания (количества + причины по позициям, set-семантика), кросс-персон
 * ({@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct}). `qty ≤ held` — held — свойство владельца
 * строки ({@see ProfileCompliance}), не акта, поэтому проверяем здесь, по каждой затронутой строке, до вызова
 * домена ({@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct::replacePortions}).
 */
final readonly class SaveWriteOffActCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private WriteOffActRepositoryInterface $writeOffActRepository,
        private ProfileComplianceRepositoryInterface $profileComplianceRepository,
    ) {
    }

    public function __invoke(SaveWriteOffActCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $act = $this->writeOffActRepository->findById($command->writeOffActId)
            ?? throw new AppException('Акт списания не найден.');

        $profileIdByRecordId = [];
        foreach ($act->lines() as $line) {
            $profileIdByRecordId[$line->recordId()] = $line->profileId();
        }

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
        $this->assertWithinHeld($lines, $profileIdByRecordId);

        $actDate = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($command->actDate)) ?: null;
        $orderDate = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($command->orderDate)) ?: null;

        $act->replacePortions($lines);
        $act->saveDetails(
            $command->actNumber, $actDate, Commission::fromRows($command->members),
            $command->orderNumber, $orderDate, $command->representativePosition, $command->representativeFio,
            new \DateTimeImmutable(),
        );
        $this->writeOffActRepository->add($act);
    }

    /**
     * «Нельзя списать больше, чем на руках»: для каждой строки грузим владельца (по уже известному из акта
     * profileId, кэшируя — одна организация часто означает повтор профиля на нескольких строках) и сверяем
     * количество с остатком на факте. Запись, которой ещё нет в акте (чужой recordId), сюда не долетает —
     * её отвергнет домен ({@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct::replacePortions}).
     *
     * @param list<array{recordId: string, quantity: float, reason: ?WriteOffReason}> $lines
     * @param array<string, string>                                                   $profileIdByRecordId
     */
    private function assertWithinHeld(array $lines, array $profileIdByRecordId): void
    {
        /** @var array<string, ProfileCompliance> $loaded */
        $loaded = [];
        foreach ($lines as $line) {
            $profileId = $profileIdByRecordId[$line['recordId']] ?? null;
            if (null === $profileId) {
                continue; // recordId не из этого акта — домен сам кинет AppException при replacePortions
            }
            $owner = $loaded[$profileId] ??= $this->profileComplianceRepository->findByProfile($profileId)
                ?? throw new AppException('Учёт по сотруднику не создан.');
            $held = $owner->recordById($line['recordId'])?->heldAmount() ?? 0.0;
            if ($line['quantity'] > $held) {
                throw new AppException(sprintf('Нельзя списать больше, чем на руках (%s).', $held));
            }
        }
    }
}
