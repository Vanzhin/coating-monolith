<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Assessment;

use App\ChemicalResistance\Application\UseCase\Command\Assessment\DeleteAssessment\DeleteAssessmentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coatings/{coatingId}/chem-resistance/assessment/{assessmentId}/delete',
    name: 'app_cabinet_coating_chem_resistance_assessment_delete',
    requirements: [
        'coatingId'    => '[0-9a-f-]{36}',
        'assessmentId' => '[0-9a-f-]{36}',
    ],
    methods: ['POST'],
)]
class DeleteAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus) {}

    public function __invoke(string $coatingId, string $assessmentId): Response
    {
        try {
            $this->commandBus->execute(new DeleteAssessmentCommand($assessmentId));
            $this->addFlash('assessment_removed_success', 'Оценка удалена.');
        } catch (AppException $e) {
            $this->addFlash('assessment_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_coating_chem_resistance_edit', ['coatingId' => $coatingId]);
    }
}
