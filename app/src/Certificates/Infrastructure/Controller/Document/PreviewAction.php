<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Controller\Document;

use App\Certificates\Application\UseCase\Query\GetDocument\GetDocumentQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Фрагмент модалки предпросмотра документа по id. Всплывает по клику на документ в модалке
 * системы (кросс-контекст: браузер зовёт этот эндпоинт напрямую). Единый паттерн с превью
 * покрытия/системы. Разметка — _document_preview.html.twig.
 */
#[Route(
    path: '/cabinet/certificate/document/{id}/preview',
    name: 'app_cabinet_certificate_document_preview',
    methods: ['GET'],
)]
#[IsGranted('ROLE_ADMIN')]
final class PreviewAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(string $id): Response
    {
        $result = $this->queryBus->execute(new GetDocumentQuery($id));
        if (null === $result->document) {
            throw $this->createNotFoundException(sprintf('Document with id "%s" not found.', $id));
        }

        return $this->render('admin/certificate/document/_document_preview.html.twig', [
            'document' => $result->document,
            'canEdit' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }
}
