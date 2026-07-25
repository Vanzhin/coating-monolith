<?php

declare(strict_types=1);

namespace App\Documents\Application\UseCase\Command\BulkInsertDocument;

use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class BulkInsertDocumentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DocumentRepositoryInterface $documentRepository,
    ) {
    }

    public function __invoke(BulkInsertDocumentCommand $command): BulkInsertDocumentCommandResult
    {
        $data = file_get_contents($command->filePath);
        $result = $this->documentRepository->bulkInsert($data, $command->db);

        return new BulkInsertDocumentCommandResult($result);
    }
}
