<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\UpdateDocument;

use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Infrastructure\Storage\DocumentFileStorage;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final readonly class UpdateDocumentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DocumentRepositoryInterface $repository,
        private DocumentFileStorage $storage,
    ) {
    }

    public function __invoke(UpdateDocumentCommand $command): UpdateDocumentCommandResult
    {
        $document = $this->repository->findOneById($command->id);
        if (null === $document) {
            throw new AppException('Документ не найден.', Response::HTTP_NOT_FOUND);
        }

        $document->setKind($command->kind);
        $document->setTitle($command->title);
        $document->setIssuerId(Uuid::fromString($command->issuerId));
        $document->setDates($command->issuedAt, $command->expiresAt);
        $document->setSubject($command->subject);
        $document->setDescription($command->description);
        $document->setTestStandard($command->testStandard);
        $document->replaceReferences(...$command->references);

        if (null !== $command->file) {
            $old = $document->getFile();
            $document->setFile($this->storage->store($command->file));
            if (null !== $old) {
                $this->storage->delete($old);
            }
        }

        $this->repository->add($document);

        return new UpdateDocumentCommandResult($document->getId());
    }
}
