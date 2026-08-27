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

#[Route(
    path: '/cabinet/coatings/{coatingId}/chem-resistance/create',
    name: 'app_cabinet_coating_chem_resistance_assessment_create',
    requirements: ['coatingId' => '[0-9a-f-]{36}'],
    methods: ['POST'],
)]
class AddAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly Validator $validator,
        private readonly AssessmentMapper $mapper,
    ) {
    }

    public function __invoke(string $coatingId, Request $req): Response
    {
        $payload = $req->getPayload()->all();

        try {
            $errors = $this->validator->validate($payload, $this->mapper->getValidationCollectionCreate());
            if ($errors) {
                throw new AppException(current($errors)->getFullMessage());
            }

            $this->commandBus->execute(new CreateAssessmentCommand(
                coatingId: $coatingId,
                substanceId: trim((string) $payload['substanceId']),
                grade: trim((string) $payload['grade']),
                maxTemperatureCelsius: AssessmentInputParser::temperature($payload['maxTemperatureCelsius'] ?? ''),
                noteIds: AssessmentInputParser::noteIds($payload['noteIds'] ?? []),
            ));
            $this->addFlash('assessment_created_success', 'Оценка добавлена.');
        } catch (\Exception $e) {
            $this->addFlash('assessment_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_coating_chem_resistance_edit', ['coatingId' => $coatingId]);
    }
}
