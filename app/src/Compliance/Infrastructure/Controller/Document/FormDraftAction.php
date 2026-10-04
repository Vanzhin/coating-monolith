<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Document;

use App\Compliance\Application\UseCase\Command\FormDraft\FormDraftCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Сформировать черновик карточки одному сотруднику по требованию (ручной запуск). Права — в хендлере. */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/requirement/{requirementId}/form-draft',
    name: 'app_cabinet_compliance_form_draft',
    methods: ['POST'],
)]
final class FormDraftAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(string $profileId, string $requirementId): Response
    {
        try {
            $this->commandBus->execute(new FormDraftCommand($profileId, $requirementId));
            $this->addFlash('success', 'Черновик карточки сформирован.');
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_compliance_dashboard', ['profile' => $profileId]);
    }
}
