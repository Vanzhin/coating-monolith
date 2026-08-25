<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\DeleteDocument;

use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Infrastructure\Storage\DocumentFileStorage;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\HttpFoundation\Response;

final readonly class DeleteDocumentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DocumentRepositoryInterface $repository,
        private DocumentFileStorage $storage,
    ) {
    }

    public function __invoke(DeleteDocumentCommand $command): void
    {
        $document = $this->repository->findOneById($command->id);
        if (null === $document) {
            throw new AppException('Документ не найден.', Response::HTTP_NOT_FOUND);
        }

        $file = $document->getFile();
        $this->repository->remove($document);
        if (null !== $file) {
            $this->storage->delete($file);
        }
    }
}
