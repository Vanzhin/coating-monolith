<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SignDraft;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\File\RequirementScanPurpose;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Infrastructure\Mapper\IssuanceLineMapper;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

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

        // Кол-во проверяем ДО промоута — staged-скан не сгорает при ошибке (переиспользуется при повторе).
        $profileCompliance->assertIssuable($lines);

        $staged = trim((string) $command->stagedFileId);
        $fileId = '' === $staged ? null : $this->storage->promote($staged, RequirementScanPurpose::SignedCard, $profileCompliance->getId())->id();

        try {
            $profileCompliance->signDraft($command->documentId, $fileId, $lines, $this->calculator, new \DateTimeImmutable());
            $this->repository->add($profileCompliance);
        } catch (\Throwable $e) {
            if (null !== $fileId) {
                $this->storage->remove($fileId); // домен отказал — не оставляем осиротевший скан
            }
            throw $e;
        }
    }
}
