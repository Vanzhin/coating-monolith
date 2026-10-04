<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Document;

use App\Compliance\Application\UseCase\Command\DeleteDraft\DeleteDraftCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Удалить открытый черновик карточки. Права — в хендлере. */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/document/{documentId}/draft-delete',
    name: 'app_cabinet_compliance_draft_delete',
    methods: ['POST'],
)]
final class DeleteDraftAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(string $profileId, string $documentId): Response
    {
        try {
            $this->commandBus->execute(new DeleteDraftCommand($profileId, $documentId));
            $this->addFlash('success', 'Черновик удалён.');
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_compliance_dashboard', ['profile' => $profileId]);
    }
}
