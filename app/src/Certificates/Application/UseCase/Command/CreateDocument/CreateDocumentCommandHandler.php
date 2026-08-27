<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\CreateDocument;

use App\Certificates\Application\Service\AccessControl\DocumentAccessControl;
use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Infrastructure\Storage\DocumentFileStorage;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

final readonly class CreateDocumentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DocumentRepositoryInterface $repository,
        private DocumentFileStorage $storage,
        private DocumentAccessControl $access,
    ) {
    }

    public function __invoke(CreateDocumentCommand $command): CreateDocumentCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        // Сначала строим документ (доменная валидация), только потом кладём файл —
        // иначе при ошибке инварианта осиротеет сохранённый скан.
        $document = new Document(
            Uuid::v7(),
            $command->kind,
            $command->title,
            Uuid::fromString($command->issuerId),
            $command->issuedAt,
            $command->expiresAt,
            $command->subject,
            $command->description,
            $command->testStandard,
            null,
            ...$command->references,
        );
        if (null !== $command->file) {
            $document->setFile($this->storage->store($command->file));
        }
        $this->repository->add($document);

        return new CreateDocumentCommandResult($document->getId());
    }
}
