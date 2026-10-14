<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\RecordIssuance;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Aggregate\ProfileCompliance\IssuanceLine;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Infrastructure\Mapper\IssuanceLineMapper;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

/**
 * Прямая дозапись выдачи в журнал. Строки формы (+ позиции вне нормы) собираем в {@see IssuanceLine} и отдаём
 * домену ОДНИМ вызовом: требование с видом журнала → {@see ProfileCompliance::recordInstruction} (инвариант
 * полноты полей инструктажа), иначе → {@see ProfileCompliance::recordIssuance} (инвариант «не меньше нормы»).
 * Инварианты живут в домене — хендлер только оркеструет (достать учёт, собрать строки, записать). Черновика,
 * топ-апа, подписи и скана здесь нет: форма шлёт одну строку на позицию, запись неизменяема сразу.
 */
final readonly class RecordIssuanceCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
        private ObligationDueCalculator $calculator,
        private IssuanceLineMapper $mapper,
        private RequirementRepositoryInterface $requirements,
    ) {
    }

    public function __invoke(RecordIssuanceCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан.');

        $documentDate = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($command->documentDate))
            ?: throw new AppException('Укажите дату документа.');

        $lines = $this->mapper->fromInput($command->items, $documentDate);
        $lines = [...$lines, ...$this->materializePersonalItems($profileCompliance, $command, $documentDate)];

        $journalKind = $this->requirements->findOneById($command->requirementId)?->getJournalKind();
        if (null !== $journalKind) {
            $profileCompliance->recordInstruction($this->calculator, $journalKind, ...$lines);
        } else {
            $profileCompliance->recordIssuance($this->calculator, ...$lines);
        }
        $this->repository->add($profileCompliance);
    }

    /**
     * Позиции вне нормы, добавленные прямо в форму → материализуем в персональные обязанности (origin=Personal,
     * cadence «по дате»). Идемпотентно: если обязанность с таким ключом уже есть — не добавляем повторно, строку
     * вешаем на существующий ключ. Возвращает строки выдачи этих позиций.
     *
     * @return list<IssuanceLine>
     */
    private function materializePersonalItems(ProfileCompliance $profileCompliance, RecordIssuanceCommand $command, \DateTimeImmutable $documentDate): array
    {
        if ([] === $command->personalItems) {
            return [];
        }
        $lines = [];
        foreach ($command->personalItems as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ('' === $label) {
                continue;
            }
            $quantity = $this->mapper->quantityOf($row); // null, если поля кол-ва нет (нематериальный акт)
            $due = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) ($row['manualDueDate'] ?? ''))) ?: null;
            $key = TrackedObligation::keyOf($command->requirementId, $label);
            if (!$this->hasObligation($profileCompliance, $key)) {
                $profileCompliance->addPersonalObligation(Uuid::v7(), $command->requirementId, $label, new Cadence(CadenceKind::ByManufacturerDoc), $quantity);
            }
            $lines[] = new IssuanceLine(Uuid::v7(), $key, $documentDate, $quantity, null, $due, $this->mapper->note($row), $this->mapper->instructionDetails($row));
        }

        return $lines;
    }

    private function hasObligation(ProfileCompliance $profileCompliance, string $key): bool
    {
        foreach ($profileCompliance->getObligations() as $obligation) {
            if ($obligation->key() === $key) {
                return true;
            }
        }

        return false;
    }
}
