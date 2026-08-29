<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Assessment;

use App\ChemicalResistance\Application\UseCase\Command\Assessment\UpdateAssessment\UpdateAssessmentCommand;
use App\ChemicalResistance\Domain\Repository\AssessmentRepositoryInterface;
use App\ChemicalResistance\Infrastructure\Mapper\AssessmentMapper;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Править химстойкость покрытия к веществу прямо со страницы «Химстойкость»
 * (грейд/температура). Тонкий: переиспользует UpdateAssessmentCommand. Привязанные
 * примечания сохраняем как есть — на этой странице их не трогаем.
 */
#[Route(
    path: '/cabinet/chemical-resistance/by-substance/assessment/{assessmentId}/update',
    name: 'app_cabinet_chemical_resistance_by_substance_assessment_update',
    requirements: ['assessmentId' => '[0-9a-f-]{36}'],
    methods: ['POST'],
)]
class UpdateFromSubstanceAction extends AbstractController
{
    use RedirectsToBySubstanceTrait;

    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly AssessmentRepositoryInterface $assessments,
        private readonly Validator $validator,
        private readonly AssessmentMapper $mapper,
    ) {
    }

    public function __invoke(string $assessmentId, Request $request): Response
    {
        $payload = $request->getPayload()->all();

        try {
            $assessment = $this->assessments->findOneById($assessmentId);
            if (null === $assessment) {
                throw new AppException('Оценка не найдена.', Response::HTTP_NOT_FOUND);
            }

            $errors = $this->validator->validate($payload, $this->mapper->getValidationCollectionUpdate());
            if ($errors) {
                throw new AppException(current($errors)->getFullMessage());
            }

            $this->commandBus->execute(new UpdateAssessmentCommand(
                id: $assessmentId,
                grade: trim((string) $payload['grade']),
                maxTemperatureCelsius: AssessmentInputParser::temperature($payload['maxTemperatureCelsius'] ?? ''),
                // Примечания не редактируем на этой странице — сохраняем текущие.
                noteIds: $assessment->getNoteIds()->getList(),
            ));
            $this->addFlash('assessment_updated_success', 'Химстойкость обновлена.');
        } catch (\Exception $e) {
            $this->addFlash('assessment_error', $e->getMessage());
        }

        return $this->redirectToBySubstance($request);
    }
}
