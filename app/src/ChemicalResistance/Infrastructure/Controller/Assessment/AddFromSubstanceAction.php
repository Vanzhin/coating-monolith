<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Assessment;

use App\ChemicalResistance\Application\UseCase\Command\Assessment\CreateAssessment\CreateAssessmentCommand;
use App\ChemicalResistance\Infrastructure\Mapper\AssessmentMapper;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Добавить покрытие к веществу прямо со страницы «Химстойкость» (создать оценку).
 * Тонкий: переиспользует CreateAssessmentCommand (гейт админа — в команде).
 * Тело — coatingId/substanceId/grade/maxTemperatureCelsius; фильтр для возврата
 * (substanceIds[]/includeAll) — в query action-URL.
 */
#[Route(
    path: '/cabinet/chemical-resistance/by-substance/assessment/create',
    name: 'app_cabinet_chemical_resistance_by_substance_assessment_create',
    methods: ['POST'],
)]
class AddFromSubstanceAction extends AbstractController
{
    use RedirectsToBySubstanceTrait;

    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly Validator $validator,
        private readonly AssessmentMapper $mapper,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $payload = $request->getPayload()->all();

        try {
            $errors = $this->validator->validate($payload, $this->mapper->getValidationCollectionCreate());
            if ($errors) {
                throw new AppException(current($errors)->getFullMessage());
            }

            $coatingId = trim((string) ($payload['coatingId'] ?? ''));
            if ('' === $coatingId) {
                throw new AppException('Выберите покрытие.');
            }

            $this->commandBus->execute(new CreateAssessmentCommand(
                coatingId: $coatingId,
                substanceId: trim((string) $payload['substanceId']),
                grade: trim((string) $payload['grade']),
                maxTemperatureCelsius: AssessmentInputParser::temperature($payload['maxTemperatureCelsius'] ?? ''),
                noteIds: [],
            ));
            $this->addFlash('assessment_created_success', 'Покрытие добавлено к веществу.');
        } catch (\Exception $e) {
            $this->addFlash('assessment_error', $e->getMessage());
        }

        return $this->redirectToBySubstance($request);
    }
}
