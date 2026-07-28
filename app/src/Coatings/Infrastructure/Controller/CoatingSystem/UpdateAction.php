<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\UseCase\Command\UpdateCoatingSystemMetadata\UpdateCoatingSystemMetadataCommand;
use App\Coatings\Application\UseCase\Query\FindCoatingSystemById\FindCoatingSystemByIdQuery;
use App\Coatings\Application\UseCase\Query\FindSurfaceTreatmentById\FindSurfaceTreatmentByIdQuery;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Infrastructure\Mapper\CoatingSystemMapper;
use App\Coatings\Infrastructure\Validation\CoatingSystemErrorFormatter;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route(path: '/cabinet/coating/coating-system/{id}/update', name: 'app_cabinet_coating_system_update', requirements: ['id' => '[0-9a-f-]{36}'])]
class UpdateAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly Validator $validator,
        private readonly CoatingSystemMapper $mapper,
        private readonly CoatingSystemErrorFormatter $errorFormatter,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $dto = $this->queryBus->execute(new FindCoatingSystemByIdQuery($id));
        if (null === $dto) {
            $this->addFlash('coating_system_error', sprintf('Система покрытий "%s" не найдена.', $id));

            return $this->redirectToRoute('app_cabinet_coating_system_list');
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = [];
            try {
                $inputData = $request->getPayload()->all();
                $errors = $this->validator->validate($inputData, $this->mapper->getValidationCollection());
                if ($errors) {
                    throw new AppException($this->errorFormatter->format($errors));
                }
                /** @var UpdateCoatingSystemMetadataCommand $command */
                $command = $this->mapper->buildCommandFromInputData($inputData, $id);
                $this->commandBus->execute($command);
                $this->addFlash('coating_system_updated_success', sprintf('Система покрытий "%s" обновлена.', $command->title));

                return $this->redirectToRoute('app_cabinet_coating_system_list');
            } catch (\Exception $e) {
                $error = $e->getMessage();
                $inputData = $this->enrichWithTreatmentTitle($inputData);

                return $this->render('cabinet/coating/coating_system/form.html.twig', [
                    'error' => $error,
                    'inputData' => $inputData,
                    'systemId' => $id,
                    'substrates' => Substrate::cases(),
                ]);
            }
        }

        $inputData = $this->mapper->buildInputDataFromDto($dto);

        return $this->render('cabinet/coating/coating_system/form.html.twig', [
            'inputData' => $inputData,
            'systemId' => $id,
            'substrates' => Substrate::cases(),
        ]);
    }

    /**
     * После POST-ошибки подтягиваем заголовок выбранной подготовки поверхности,
     * чтобы async-typeahead мог восстановить preselected-тег.
     *
     * @param array<string, mixed> $inputData
     *
     * @return array<string, mixed>
     */
    private function enrichWithTreatmentTitle(array $inputData): array
    {
        $treatmentId = $inputData['surfaceTreatmentId'] ?? null;
        if (is_string($treatmentId) && '' !== $treatmentId && Uuid::isValid($treatmentId)) {
            $treatmentDto = $this->queryBus->execute(new FindSurfaceTreatmentByIdQuery($treatmentId));
            if (null !== $treatmentDto) {
                $inputData['surfaceTreatmentTitle'] = $treatmentDto->title;
            }
        }

        return $inputData;
    }
}
