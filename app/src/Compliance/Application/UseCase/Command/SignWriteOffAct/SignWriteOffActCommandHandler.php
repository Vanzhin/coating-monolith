<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SignWriteOffAct;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Application\Service\ComplianceObligationProjector;
use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffActLine;
use App\Compliance\Domain\Event\WriteOffActSigned;
use App\Compliance\Domain\File\RequirementScanPurpose;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Domain\ValueObject\Commission;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Синхронная часть оформления акта списания (кросс-персон {@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct}):
 * промоут скана, домен замораживает акт, затем Application гасит количество фактам ВСЕХ затронутых людей —
 * fan-out по {@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct::linesByProfile} внутри ОДНОЙ
 * транзакции (`doctrine_transaction` middleware шины команд оборачивает весь `__invoke`). Производное
 * (пересчёт проекции каждого человека + черновик выдачи на дефицит) — в воркере событием {@see WriteOffActSigned},
 * пользователь не ждёт. Отказ (домена или кого-то из профилей) → снимаем осиротевший скан.
 */
final readonly class SignWriteOffActCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private WriteOffActRepositoryInterface $writeOffActRepository,
        private ProfileComplianceRepositoryInterface $profileComplianceRepository,
        private FileStorage $storage,
        private EventBusInterface $eventBus,
        private ComplianceObligationProjector $projector,
    ) {
    }

    public function __invoke(SignWriteOffActCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $act = $this->writeOffActRepository->findById($command->writeOffActId)
            ?? throw new AppException('Акт списания не найден.');

        $now = new \DateTimeImmutable();
        $actDate = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($command->actDate))
            ?: throw new AppException('Укажите дату акта списания.');
        $commission = Commission::fromRows($command->members);

        $staged = trim((string) $command->stagedFileId);
        if ('' === $staged) {
            throw new AppException('Приложите скан подписанного акта списания.');
        }
        $fileId = $this->storage->promote($staged, RequirementScanPurpose::WriteOffActScan, $act->getId())->id();

        try {
            $act->sign($commission, $command->actNumber, $actDate, $fileId, $now);

            foreach ($act->linesByProfile() as $profileId => $lines) {
                $profileCompliance = $this->profileComplianceRepository->findByProfile((string) $profileId)
                    ?? throw new AppException('Учёт по сотруднику не создан.');
                $profileCompliance->applyWriteOff($this->sumByRecordId($lines));
                $this->profileComplianceRepository->add($profileCompliance);

                // Write-through: гасим и материализуем строки новой read-модели КАЖДОГО затронутого человека
                // (fan-out кросс-персон акта) в ТОЙ ЖЕ транзакции. Пересборка старой таблицы — по-прежнему async
                // событием {@see WriteOffActSigned}; это намеренный параллельный прогон (разные таблицы).
                $this->projector->projectProfile((string) $profileId, $act->requirementId());
            }

            $this->writeOffActRepository->add($act);
        } catch (\Throwable $e) {
            $this->storage->remove($fileId);
            throw $e;
        }

        $this->eventBus->execute(new WriteOffActSigned($act->requirementId(), $act->profileIds()));
    }

    /**
     * @param list<WriteOffActLine> $lines строки ОДНОГО профиля (сгруппированы {@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct::linesByProfile})
     *
     * @return array<string, float> recordId → Σ количества (одна строка на recordId в акте — сумма защитная)
     */
    private function sumByRecordId(array $lines): array
    {
        $sums = [];
        foreach ($lines as $line) {
            $sums[$line->recordId()] = ($sums[$line->recordId()] ?? 0.0) + $line->quantity();
        }

        return $sums;
    }
}
