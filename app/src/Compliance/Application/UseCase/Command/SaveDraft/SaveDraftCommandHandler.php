<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SaveDraft;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Aggregate\ProfileCompliance\IssuanceLine;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\File\RequirementScanPurpose;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Infrastructure\Mapper\IssuanceLineMapper;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

/**
 * Сохранение/оформление черновика единой командой. Строки формы (+ персональные позиции) собираем всегда.
 * Нет скана → `saveDraft` (корзина + реквизиты, без подписи, held не трогаем). Есть скан → проверка кол-ва ДО
 * промоута (staged не сгорает при ошибке) → промоут → `signDraft` (инвариант + факты + подпись + трекинг);
 * отказ домена → снимаем осиротевший скан.
 */
final readonly class SaveDraftCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
        private ObligationDueCalculator $calculator,
        private FileStorage $storage,
        private IssuanceLineMapper $mapper,
    ) {
    }

    public function __invoke(SaveDraftCommand $command): void
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

        $now = new \DateTimeImmutable();
        $staged = trim((string) $command->stagedFileId);

        if ('' === $staged) {
            // Сохранение черновика: без скана, без инварианта кол-ва — корзину можно сохранить недозаполненной.
            $profileCompliance->saveDraft($command->documentId, $command->actNumber, $command->responsibleFio, $lines, $now);
            $this->repository->add($profileCompliance);

            return;
        }

        // Оформление: кол-во проверяем ДО промоута — staged-скан не сгорает при ошибке (переиспользуется при повторе).
        $profileCompliance->assertIssuable($lines);
        $fileId = $this->storage->promote($staged, RequirementScanPurpose::SignedCard, $profileCompliance->getId())->id();

        try {
            $profileCompliance->signDraft($command->documentId, $fileId, $lines, $this->calculator, $now, $command->actNumber, $command->responsibleFio);
            $this->repository->add($profileCompliance);
        } catch (\Throwable $e) {
            $this->storage->remove($fileId); // домен отказал — не оставляем осиротевший скан
            throw $e;
        }
    }

    /**
     * Позиции вне нормы, добавленные прямо в акт → материализуем в персональные обязанности (origin=Personal,
     * cadence «по дате»). Идемпотентно: если обязанность с таким ключом уже есть (повторное сохранение) —
     * не добавляем повторно, строку вешаем на существующий ключ. Возвращает строки выдачи этих позиций.
     *
     * @return list<IssuanceLine>
     */
    private function materializePersonalItems(ProfileCompliance $profileCompliance, SaveDraftCommand $command, \DateTimeImmutable $documentDate): array
    {
        if ([] === $command->personalItems) {
            return [];
        }
        $requirementId = $this->requirementIdOfDocument($profileCompliance, $command->documentId);
        $lines = [];
        foreach ($command->personalItems as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ('' === $label) {
                continue;
            }
            $quantity = $this->mapper->quantityOf($row); // null, если поля кол-ва нет (нематериальный акт)
            $due = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) ($row['manualDueDate'] ?? ''))) ?: null;
            $key = TrackedObligation::keyOf($requirementId, $label);
            if (!$this->hasObligation($profileCompliance, $key)) {
                $profileCompliance->addPersonalObligation(Uuid::v7(), $requirementId, $label, new Cadence(CadenceKind::ByManufacturerDoc), $quantity);
            }
            $lines[] = new IssuanceLine(Uuid::v7(), $key, $documentDate, $quantity, null, $due);
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

    private function requirementIdOfDocument(ProfileCompliance $profileCompliance, string $documentId): string
    {
        foreach ($profileCompliance->getDocuments() as $document) {
            if ($document->getId() === $documentId) {
                return $document->requirementId();
            }
        }

        throw new AppException('Черновик не найден.');
    }
}
