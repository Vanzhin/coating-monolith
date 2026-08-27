<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Controller\Document;

use App\Certificates\Application\UseCase\Command\DeleteDocument\DeleteDocumentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/certificate/document/{id}/delete',
    name: 'app_cabinet_certificate_document_delete',
)]
final class DeleteAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(string $id): Response
    {
        try {
            $this->commandBus->execute(new DeleteDocumentCommand($id));
            $this->addFlash('document_removed_success', 'Документ удалён.');
        } catch (\Exception|\Error $e) {
            $this->addFlash('document_removed_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_certificate_document_list');
    }
}
