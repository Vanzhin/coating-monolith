<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\SurfaceTreatment;

use App\Coatings\Application\UseCase\Command\UpdateSurfaceTreatment\UpdateSurfaceTreatmentCommand;
use App\Coatings\Application\UseCase\Query\FindSurfaceTreatmentById\FindSurfaceTreatmentByIdQuery;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Infrastructure\Mapper\SurfaceTreatmentMapper;
use App\Coatings\Infrastructure\Validation\SurfaceTreatmentErrorFormatter;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/surface-treatment/{id}/update', name: 'app_cabinet_surface_treatment_update', requirements: ['id' => '[0-9a-f-]{36}'])]
class UpdateAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly Validator $validator,
        private readonly SurfaceTreatmentMapper $mapper,
        private readonly SurfaceTreatmentErrorFormatter $errorFormatter,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $dto = $this->queryBus->execute(new FindSurfaceTreatmentByIdQuery($id));
        if (null === $dto) {
            $this->addFlash('surface_treatment_error', sprintf('Подготовка поверхности "%s" не найдена.', $id));

            return $this->redirectToRoute('app_cabinet_surface_treatment_list');
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = [];
            try {
                $inputData = $request->getPayload()->all();
                $errors = $this->validator->validate($inputData, $this->mapper->getValidationCollection());
                if ($errors) {
                    throw new AppException($this->errorFormatter->format($errors));
                }
                /** @var UpdateSurfaceTreatmentCommand $command */
                $command = $this->mapper->buildCommandFromInputData($inputData, $id);
                $this->commandBus->execute($command);
                $this->addFlash('surface_treatment_updated_success', 'Подготовка поверхности обновлена.');

                return $this->redirectToRoute('app_cabinet_surface_treatment_list');
            } catch (\Exception $e) {
                $error = $e->getMessage();

                return $this->render('cabinet/coating/surface_treatment/form.html.twig', [
                    'error' => $error,
                    'inputData' => $inputData,
                    'treatmentId' => $id,
                    'substrates' => Substrate::cases(),
                ]);
            }
        }

        $inputData = $this->mapper->buildInputDataFromDto($dto);

        return $this->render('cabinet/coating/surface_treatment/form.html.twig', [
            'inputData' => $inputData,
            'treatmentId' => $id,
            'substrates' => Substrate::cases(),
        ]);
    }
}
