<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Assessment;

use App\ChemicalResistance\Application\UseCase\Command\Assessment\DeleteAssessment\DeleteAssessmentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Убрать покрытие из вещества прямо со страницы «Химстойкость» (удалить оценку).
 * Тонкий: переиспользует DeleteAssessmentCommand (гейт админа — в команде).
 */
#[Route(
    path: '/cabinet/chemical-resistance/by-substance/assessment/{assessmentId}/delete',
    name: 'app_cabinet_chemical_resistance_by_substance_assessment_delete',
    requirements: ['assessmentId' => '[0-9a-f-]{36}'],
    methods: ['GET', 'POST'],
)]
class DeleteFromSubstanceAction extends AbstractController
{
    use RedirectsToBySubstanceTrait;

    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $assessmentId, Request $request): Response
    {
        try {
            $this->commandBus->execute(new DeleteAssessmentCommand($assessmentId));
            $this->addFlash('assessment_updated_success', 'Покрытие убрано из вещества.');
        } catch (\Exception $e) {
            $this->addFlash('assessment_error', $e->getMessage());
        }

        return $this->redirectToBySubstance($request);
    }
}
