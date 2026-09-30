<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\RecordIssuance;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\File\RequirementScanPurpose;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

/**
 * Правила выдачи (положительность кол-ва/процента, формат дат) — в VO/парсинге; пересчёт дат обязанности —
 * в агрегате ({@see \App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance::recordFulfillment()}).
 * Скан привязывается promote → ownerId = id учёта.
 */
final readonly class RecordIssuanceCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProfileComplianceRepositoryInterface $repository,
        private ComplianceAccessControl $access,
        private ObligationDueCalculator $calculator,
        private FileStorage $storage,
    ) {
    }

    public function __invoke(RecordIssuanceCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId);
        if (null === $profileCompliance) {
            throw new AppException('Учёт по сотруднику не создан — сначала сформируйте учёт.');
        }
        $documentDate = $this->parseDate($command->documentDate) ?? throw new AppException('Укажите дату документа.');

        foreach ($command->items as $row) {
            $key = trim((string) ($row['obligationKey'] ?? ''));
            if ('' === $key) {
                continue;
            }
            $fileId = null;
            $staged = trim((string) ($row['stagedFileId'] ?? ''));
            if ('' !== $staged) {
                $fileId = $this->storage->promote($staged, RequirementScanPurpose::FulfillmentScan, $profileCompliance->getId())->id();
            }

            $profileCompliance->recordFulfillment(
                Uuid::v7(),
                $key,
                $this->parseDate((string) ($row['date'] ?? '')) ?? $documentDate,
                $this->calculator,
                $this->quantity($row),
                $this->wear($row),
                $command->note,
                $fileId,
                $this->parseDate((string) ($row['manualDueDate'] ?? '')),
            );
        }

        $this->repository->add($profileCompliance);
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date ?: throw new AppException(sprintf('Неверный формат даты: «%s».', $value));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function quantity(array $row): ?Quantity
    {
        $amount = trim((string) ($row['amount'] ?? ''));
        if ('' === $amount) {
            return null;
        }
        $unit = Unit::tryFrom((string) ($row['unit'] ?? '')) ?? throw new AppException('Выберите единицу измерения количества.');

        return new Quantity((float) str_replace(',', '.', $amount), $unit);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function wear(array $row): ?Percent
    {
        $wear = trim((string) ($row['wearPercent'] ?? ''));

        return '' === $wear ? null : new Percent((float) str_replace(',', '.', $wear));
    }
}
