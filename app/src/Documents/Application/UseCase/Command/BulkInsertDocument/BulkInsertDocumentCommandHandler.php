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
        private DocumentAccessControl $access,
    ) {
    }

    public function __invoke(BulkInsertDocumentCommand $command): BulkInsertDocumentCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $data = file_get_contents($command->filePath);
        $result = $this->documentRepository->bulkInsert($data);

        return new BulkInsertDocumentCommandResult($result);
    }
}
