<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Controller\Document;

use App\Certificates\Application\UseCase\Query\GetDocument\GetDocumentQuery;
use App\Certificates\Infrastructure\Storage\DocumentFileStorage;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/certificate/document/{id}/download',
    name: 'app_cabinet_certificate_document_download',
    methods: ['GET'],
)]
#[IsGranted('ROLE_ADMIN')]
final class DownloadAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly DocumentFileStorage $storage,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $result = $this->queryBus->execute(new GetDocumentQuery($id));
        $document = $result->document;
        if (null === $document || null === $document->file) {
            throw new AppException('Файл документа не найден.', Response::HTTP_NOT_FOUND);
        }

        $stream = $this->storage->readStream($document->file);

        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
        });
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->fileName($document->title)),
        );

        return $response;
    }

    private function fileName(string $title): string
    {
        $safe = preg_replace('/[^\p{L}\p{N}\-_. ]+/u', '', $title) ?? 'document';
        $safe = trim($safe);

        return ('' === $safe ? 'document' : $safe).'.pdf';
    }
}
