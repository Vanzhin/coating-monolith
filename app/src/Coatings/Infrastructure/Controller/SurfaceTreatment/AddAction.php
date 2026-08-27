<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\SurfaceTreatment;

use App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment\CreateSurfaceTreatmentCommand;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Infrastructure\Mapper\SurfaceTreatmentMapper;
use App\Coatings\Infrastructure\Validation\SurfaceTreatmentErrorFormatter;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/cabinet/coating/surface-treatment/add', name: 'app_cabinet_surface_treatment_add')]
#[IsGranted('ROLE_ADMIN')]
class AddAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly Validator $validator,
        private readonly SurfaceTreatmentMapper $mapper,
        private readonly SurfaceTreatmentErrorFormatter $errorFormatter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = [];
            try {
                $inputData = $request->getPayload()->all();
                $errors = $this->validator->validate($inputData, $this->mapper->getValidationCollection());
                if ($errors) {
                    throw new AppException($this->errorFormatter->format($errors));
                }
                /** @var CreateSurfaceTreatmentCommand $command */
                $command = $this->mapper->buildCommandFromInputData($inputData);
                $this->commandBus->execute($command);
                $this->addFlash('surface_treatment_created_success', 'Подготовка поверхности добавлена.');

                return $this->redirectToRoute('app_cabinet_surface_treatment_list');
            } catch (\Exception $e) {
                $error = $e->getMessage();

                return $this->render('cabinet/coating/surface_treatment/form.html.twig', [
                    'error' => $error,
                    'inputData' => $inputData,
                    'substrates' => Substrate::cases(),
                ]);
            }
        }

        return $this->render('cabinet/coating/surface_treatment/form.html.twig', [
            'inputData' => null,
            'substrates' => Substrate::cases(),
        ]);
    }
}
