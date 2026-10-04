<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SignDraft;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Aggregate\ProfileCompliance\IssuanceLine;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
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
 * Оформление черновика: строки из формы → проверка кол-ва ДО промоута скана (staged не сгорает при ошибке) →
 * промоут скана → домен оформляет (инвариант + факты + подпись + трекинг). Отказ домена → снимаем осиротевший скан.
 */
final readonly class SignDraftCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
        private ObligationDueCalculator $calculator,
        private FileStorage $storage,
        private IssuanceLineMapper $mapper,
    ) {
    }

    public function __invoke(SignDraftCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан.');

        $documentDate = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($command->documentDate))
            ?: throw new AppException('Укажите дату документа.');
        $lines = $this->mapper->fromInput($command->items, $documentDate);

        // Добавленные прямо в акт позиции вне нормы → материализуем в персональные обязанности (origin=Personal,
        // cadence «по дате»; тип и наличие нормы домен выводит сам). Строки выдачи дописываем; инвариант «срок
        // обязателен» проверяет домен в assertIssuable.
        if ([] !== $command->personalItems) {
            $requirementId = $this->requirementIdOfDocument($profileCompliance, $command->documentId);
            foreach ($command->personalItems as $row) {
                $label = trim((string) ($row['label'] ?? ''));
                if ('' === $label) {
                    continue;
                }
                $quantity = $this->mapper->quantityOf($row); // null, если поля кол-ва нет (нематериальный акт)
                $due = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) ($row['manualDueDate'] ?? ''))) ?: null;
                $key = $profileCompliance->addPersonalObligation(Uuid::v7(), $requirementId, $label, new Cadence(CadenceKind::ByManufacturerDoc), $quantity);
                $lines[] = new IssuanceLine(Uuid::v7(), $key, $documentDate, $quantity, null, $due);
            }
        }

        // Кол-во проверяем ДО промоута — staged-скан не сгорает при ошибке (переиспользуется при повторе).
        $profileCompliance->assertIssuable($lines);

        $staged = trim((string) $command->stagedFileId);
        $fileId = '' === $staged ? null : $this->storage->promote($staged, RequirementScanPurpose::SignedCard, $profileCompliance->getId())->id();

        try {
            $profileCompliance->signDraft($command->documentId, $fileId, $lines, $this->calculator, new \DateTimeImmutable(), $command->actNumber, $command->responsibleFio);
            $this->repository->add($profileCompliance);
        } catch (\Throwable $e) {
            if (null !== $fileId) {
                $this->storage->remove($fileId); // домен отказал — не оставляем осиротевший скан
            }
            throw $e;
        }
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
