<?php

declare(strict_types=1);


namespace App\Shared\Application\Command\UseCase\Command\InitDb;

use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class InitDbCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DocumentRepositoryInterface $documentRepository,
    )
    {
    }

    public function __invoke(InitDbCommand $command): InitDbCommandResult
    {
        $result = $this->documentRepository->dbCreate($command->dbTitle);

        return new InitDbCommandResult($result);
    }
}
