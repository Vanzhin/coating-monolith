<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Document;

use App\Compliance\Application\UseCase\Command\DeleteDocument\DeleteDocumentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Удалить документ выдачи (админ) — каскадом с его записями и связанными списаниями. */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/document/{documentId}/delete',
    name: 'app_cabinet_compliance_document_delete',
    methods: ['POST'],
)]
final class DeleteDocumentAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(string $profileId, string $documentId): Response
    {
        try {
            $this->commandBus->execute(new DeleteDocumentCommand($profileId, $documentId));
            $this->addFlash('success', 'Акт удалён вместе со связанными списаниями.');
        } catch (AppException $e) {
            // Доменные/прав-ошибки (ForbiddenException — наследник AppException) — человекочитаемый flash.
            // Технические (DB/оптимистичная блокировка) не глушим — всплывут в глобальный обработчик.
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_compliance_acts', ['profile' => $profileId]);
    }
}
