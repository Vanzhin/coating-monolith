<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Assessment;

use App\ChemicalResistance\Application\UseCase\Command\Assessment\UpdateAssessment\UpdateAssessmentCommand;
use App\ChemicalResistance\Domain\Repository\AssessmentRepositoryInterface;
use App\ChemicalResistance\Domain\Repository\NoteRepositoryInterface;
use App\ChemicalResistance\Domain\Repository\NotesFilter;
use App\ChemicalResistance\Domain\Repository\SubstanceRepositoryInterface;
use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQuery;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coatings/{coatingId}/chem-resistance/assessment/{assessmentId}/edit',
    name: 'app_cabinet_coating_chem_resistance_assessment_update',
    requirements: [
        'coatingId'    => '[0-9a-f-]{36}',
        'assessmentId' => '[0-9a-f-]{36}',
    ],
)]
class UpdateAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly AssessmentRepositoryInterface $assessmentRepository,
        private readonly SubstanceRepositoryInterface $substances,
        private readonly NoteRepositoryInterface $notes,
    ) {}

    public function __invoke(string $coatingId, string $assessmentId, Request $req): Response
    {
        $assessment = $this->assessmentRepository->findOneById($assessmentId);
        if ($assessment === null) {
            $this->addFlash('assessment_error', 'Оценка не найдена.');
            return $this->redirectToRoute('app_cabinet_coating_chem_resistance_edit', ['coatingId' => $coatingId]);
        }

        $coatingResult = $this->queryBus->execute(new GetCoatingQuery($coatingId));
        if ($coatingResult->coatingDTO === null) {
            throw new AppException(sprintf('Покрытие «%s» не найдено.', $coatingId), 404);
        }

        $substance = $this->substances->findOneById($assessment->getSubstanceId()->toRfc4122());
        $allNotes  = $this->notes->findByFilter(new NotesFilter(null, null))->items;

        if ($req->isMethod(Request::METHOD_POST)) {
            $payload = $req->getPayload()->all();
            try {
                $this->commandBus->execute(new UpdateAssessmentCommand(
                    id:                    $assessmentId,
                    grade:                 trim((string) ($payload['grade'] ?? 'NT')),
                    maxTemperatureCelsius: AssessmentInputParser::temperature($payload['maxTemperatureCelsius'] ?? ''),
                    noteIds:               AssessmentInputParser::noteIds($payload['noteIds'] ?? ''),
                ));
                $this->addFlash('assessment_updated_success', 'Оценка обновлена.');

                return $this->redirectToRoute('app_cabinet_coating_chem_resistance_edit', ['coatingId' => $coatingId]);
            } catch (AppException $e) {
                $error = $e->getMessage();
                return $this->render('admin/chemical_resistance/assessment/form.html.twig', [
                    'coating'      => $coatingResult->coatingDTO,
                    'assessment'   => $assessment,
                    'substance'    => $substance,
                    'allNotes'     => $allNotes,
                    'coatingId'    => $coatingId,
                    'assessmentId' => $assessmentId,
                    'error'        => $error,
                    'inputData'    => $payload,
                ]);
            }
        }

        return $this->render('admin/chemical_resistance/assessment/form.html.twig', [
            'coating'      => $coatingResult->coatingDTO,
            'assessment'   => $assessment,
            'substance'    => $substance,
            'allNotes'     => $allNotes,
            'coatingId'    => $coatingId,
            'assessmentId' => $assessmentId,
        ]);
    }
}
