<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Controller\Document;

use App\Certificates\Application\UseCase\Command\DeleteDocument\DeleteDocumentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Security\CsrfGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/certificate/document/{id}/delete',
    name: 'app_cabinet_certificate_document_delete',
    methods: ['POST'],
)]
final class DeleteAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly CsrfGuard $csrfGuard,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $this->csrfGuard->assertValid('delete', $request->request->getString('_token'));
        try {
            $this->commandBus->execute(new DeleteDocumentCommand($id));
            $this->addFlash('document_removed_success', 'Документ удалён.');
        } catch (AppException $e) {
            $this->addFlash('document_removed_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_certificate_document_list');
    }
}
