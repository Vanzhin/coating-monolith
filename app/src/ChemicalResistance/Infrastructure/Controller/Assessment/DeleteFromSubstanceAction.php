<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Assessment;

use App\ChemicalResistance\Application\UseCase\Command\Assessment\DeleteAssessment\DeleteAssessmentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Security\CsrfGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Убрать покрытие из вещества прямо со страницы «Химстойкость» (удалить оценку).
 * Тонкий: переиспользует DeleteAssessmentCommand (гейт админа — в команде).
 */
#[Route(
    path: '/cabinet/chemical-resistance/by-substance/assessment/{assessmentId}/delete',
    name: 'app_cabinet_chemical_resistance_by_substance_assessment_delete',
    requirements: ['assessmentId' => '[0-9a-f-]{36}'],
    methods: ['POST'],
)]
class DeleteFromSubstanceAction extends AbstractController
{
    use RedirectsToBySubstanceTrait;

    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly CsrfGuard $csrfGuard,
    ) {
    }

    public function __invoke(string $assessmentId, Request $request): Response
    {
        $this->csrfGuard->assertValid('delete', $request->request->getString('_token'));
        try {
            $this->commandBus->execute(new DeleteAssessmentCommand($assessmentId));
            $this->addFlash('assessment_updated_success', 'Покрытие убрано из вещества.');
        } catch (AppException $e) {
            $this->addFlash('assessment_error', $e->getMessage());
        }

        return $this->redirectToBySubstance($request);
    }
}
