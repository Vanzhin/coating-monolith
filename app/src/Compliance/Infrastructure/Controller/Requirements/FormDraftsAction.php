<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Requirements;

use App\Compliance\Application\UseCase\Command\FormDraftsForRequirement\FormDraftsForRequirementCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Сформировать черновики карточек всем сотрудникам должностей требования (пакетно). Права — в хендлере. */
#[Route(
    path: '/cabinet/compliance/requirements/{requirementId}/form-drafts',
    name: 'app_cabinet_compliance_requirement_form_drafts',
    methods: ['POST'],
    requirements: ['requirementId' => '[0-9a-f\-]{36}'],
)]
final class FormDraftsAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(string $requirementId): Response
    {
        try {
            $this->commandBus->execute(new FormDraftsForRequirementCommand($requirementId));
            $this->addFlash('success', 'Запущено — карточки формируются в фоне. Обновите страницу через минуту.');
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_compliance_requirements_list');
    }
}
