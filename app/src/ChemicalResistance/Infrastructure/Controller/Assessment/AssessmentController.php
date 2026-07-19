<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Assessment;

use App\ChemicalResistance\Application\UseCase\Command\Assessment\CreateAssessment\CreateAssessmentCommand;
use App\ChemicalResistance\Application\UseCase\Command\Assessment\DeleteAssessment\DeleteAssessmentCommand;
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
use Symfony\Component\Uid\Uuid;

#[Route(
    path: '/cabinet/coatings/{coatingId}/chem-resistance',
    name: 'app_cabinet_coating_chem_resistance_assessment_',
    requirements: ['coatingId' => '[0-9a-f-]{36}'],
)]
final class AssessmentController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface   $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly AssessmentRepositoryInterface $assessmentRepository,
        private readonly NoteRepositoryInterface      $notes,
        private readonly SubstanceRepositoryInterface $substances,
    ) {}

    #[Route(path: '/create', name: 'create', methods: ['POST'])]
    public function create(string $coatingId, Request $req): Response
    {
        try {
            $payload = $req->getPayload()->all();
            $command = new CreateAssessmentCommand(
                coatingId:            $coatingId,
                substanceId:          trim($payload['substanceId'] ?? ''),
                grade:                trim($payload['grade'] ?? 'NT'),
                maxTemperatureCelsius: $this->parseTemperature($payload['maxTemperatureCelsius'] ?? ''),
                noteIds:              $this->parseNoteIds($payload['noteIds'] ?? ''),
            );
            $this->commandBus->execute($command);
            $this->addFlash('assessment_created_success', 'Оценка добавлена.');
        } catch (\Exception|\Error $e) {
            $this->addFlash('assessment_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_coating_chem_resistance_edit', ['coatingId' => $coatingId]);
    }

    #[Route(
        path: '/assessment/{assessmentId}/edit',
        name: 'update',
        requirements: ['assessmentId' => '[0-9a-f-]{36}'],
    )]
    public function update(string $coatingId, string $assessmentId, Request $req): Response
    {
        $assessment = $this->assessmentRepository->findOneById($assessmentId);
        if ($assessment === null) {
            $this->addFlash('assessment_error', 'Оценка не найдена.');
            return $this->redirectToRoute('app_cabinet_coating_chem_resistance_edit', ['coatingId' => $coatingId]);
        }

        $coatingResult = $this->queryBus->execute(new GetCoatingQuery($coatingId));
        if ($coatingResult->coatingDTO === null) {
            throw new AppException(sprintf('Покрытие "%s" не найдено.', $coatingId), 404);
        }

        $substance = $this->substances->find($assessment->getSubstanceId());
        $allNotes  = $this->notes->findByFilter(new NotesFilter(null, null))->items;

        if ($req->isMethod(Request::METHOD_POST)) {
            $payload = $req->getPayload()->all();
            try {
                $command = new UpdateAssessmentCommand(
                    id:                   $assessmentId,
                    grade:                trim($payload['grade'] ?? 'NT'),
                    maxTemperatureCelsius: $this->parseTemperature($payload['maxTemperatureCelsius'] ?? ''),
                    noteIds:              $this->parseNoteIds($payload['noteIds'] ?? ''),
                );
                $this->commandBus->execute($command);
                $this->addFlash('assessment_updated_success', 'Оценка обновлена.');

                return $this->redirectToRoute('app_cabinet_coating_chem_resistance_edit', ['coatingId' => $coatingId]);
            } catch (\Exception|\Error $e) {
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

    #[Route(
        path: '/assessment/{assessmentId}/delete',
        name: 'delete',
        requirements: ['assessmentId' => '[0-9a-f-]{36}'],
        methods: ['POST'],
    )]
    public function delete(string $coatingId, string $assessmentId): Response
    {
        try {
            $this->commandBus->execute(new DeleteAssessmentCommand($assessmentId));
            $this->addFlash('assessment_removed_success', 'Оценка удалена.');
        } catch (\Exception|\Error $e) {
            $this->addFlash('assessment_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_coating_chem_resistance_edit', ['coatingId' => $coatingId]);
    }

    private function parseTemperature(mixed $value): ?int
    {
        $str = trim((string) $value);
        if ($str === '' || $str === '0') {
            return null;
        }
        $int = (int) $str;
        return $int > 0 ? $int : null;
    }

    /** @return list<string> */
    private function parseNoteIds(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }
        $str = trim((string) $value);
        if ($str === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $str))));
    }
}
