<?php

declare(strict_types=1);

namespace App\Documents\Infrastructure\Controller\Api;

use App\Documents\Application\UseCase\Command\BulkInsertDocument\BulkInsertDocumentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/document/bulk-add', name: 'app_api_document_bulk_add', methods: ['POST'])]
class AddBulkAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $dbName = $request->getPayload()->get('db_name');
        /**
         * @var UploadedFile $file
         */
        $file = $request->files->get('documents');
        if (!$file instanceof UploadedFile) {
            throw new AppException('Файл не найден.');
        }
        $command = new BulkInsertDocumentCommand($file->getRealPath(), $dbName);
        $result = $this->commandBus->execute($command);

        return new JsonResponse($result, Response::HTTP_CREATED);
    }
}