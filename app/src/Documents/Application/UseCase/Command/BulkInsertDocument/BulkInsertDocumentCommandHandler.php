<?php

declare(strict_types=1);

namespace App\Documents\Application\UseCase\Command\BulkInsertDocument;

use App\Documents\Application\Service\AccessControl\DocumentAccessControl;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;

readonly class BulkInsertDocumentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DocumentRepositoryInterface $documentRepository,
        private DocumentAccessControl $accessControl,
    ) {
    }

    public function __invoke(BulkInsertDocumentCommand $command): BulkInsertDocumentCommandResult
    {
        // Массовая запись в индекс — только управляющий. Раньше авторизации не было вовсе:
        // любой JWT-холдер писал/вайпал произвольный ES-индекс.
        if (!$this->accessControl->canManage()) {
            throw new ForbiddenException();
        }

        $data = file_get_contents($command->filePath);
        $result = $this->documentRepository->bulkInsert($data, $command->db);

        return new BulkInsertDocumentCommandResult($result);
    }
}
