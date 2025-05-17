<?php

declare(strict_types=1);

namespace App\Shared\Application\Command\UseCase\Command\DeleteDb;

use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class DeleteDbCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DocumentRepositoryInterface $documentRepository,
    )
    {
    }

    public function __invoke(DeleteDbCommand $command): DeleteDbCommandResult
    {
        $result = $this->documentRepository->dbDelete($command->dbTitle);

        return new DeleteDbCommandResult($result);
    }
}
