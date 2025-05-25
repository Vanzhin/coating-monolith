<?php

declare(strict_types=1);

namespace App\Documents\Application\UseCase\Command\AddDocument;

use App\Documents\Domain\Factory\DocumentFactory;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class AddDocumentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DocumentFactory $documentFactory,
        private DocumentRepositoryInterface $documentRepository,
    ) {
    }

    public function __invoke(AddDocumentCommand $command): AddDocumentCommandResult
    {
        $document = $this->documentFactory->create(
            $command->dto->title,
            $command->dto->description,
            $command->dto->category,
            $command->dto->link,
            $command->dto->products,
        );
        $this->documentRepository->save($document);

        return new AddDocumentCommandResult(
            $document->getId()
        );
    }
}
