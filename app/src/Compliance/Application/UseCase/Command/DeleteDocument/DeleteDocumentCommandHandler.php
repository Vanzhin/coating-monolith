<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\DeleteDocument;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Event\DraftDeleted;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Удаление документа выдачи админом (подписанный акт = слепок, но админ вправе снести). Каскад: записи-факты +
 * связанные списания — атомарно в транзакции команды (orphan-removal). Пересчёт проекции и чистку сканов удалённых
 * актов отдаём воркеру (DraftDeleted) — строго post-commit, чтобы откат транзакции не осиротил ссылку на файл.
 */
final readonly class DeleteDocumentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(DeleteDocumentCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан.');

        $result = $profileCompliance->deleteSignedDocument($command->documentId)
            ?? throw new AppException('Документ не найден.');
        $this->repository->add($profileCompliance);

        // Пересчёт профиля по требованию (+ черновик на дефицит) и удаление сканов — в воркере, post-commit.
        $this->eventBus->execute(new DraftDeleted(
            $command->profileId,
            $result['requirementId'],
            new StringCollection(...$result['fileIds']),
        ));
    }
}
