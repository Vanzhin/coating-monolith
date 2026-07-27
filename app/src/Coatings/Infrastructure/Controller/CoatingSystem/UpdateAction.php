<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\UseCase\Command\UpdateCoatingSystemMetadata\UpdateCoatingSystemMetadataCommand;
use App\Coatings\Application\UseCase\Query\FindCoatingSystemById\FindCoatingSystemByIdQuery;
use App\Coatings\Application\UseCase\Query\ListSurfaceTreatments\ListSurfaceTreatmentsQuery;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\SurfaceTreatmentsFilter;
use App\Coatings\Infrastructure\Mapper\CoatingSystemMapper;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating-system/{id}/update', name: 'app_cabinet_coating_system_update', requirements: ['id' => '[0-9a-f-]{36}'])]
class UpdateAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly Validator $validator,
        private readonly CoatingSystemMapper $mapper,
        private readonly CoatingRepositoryInterface $coatingRepository,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $dto = $this->queryBus->execute(new FindCoatingSystemByIdQuery($id));
        if (null === $dto) {
            $this->addFlash('coating_system_error', sprintf('Система покрытий "%s" не найдена.', $id));

            return $this->redirectToRoute('app_cabinet_coating_system_list');
        }

        $treatments = $this->queryBus->execute(
            new ListSurfaceTreatmentsQuery(new SurfaceTreatmentsFilter(), 1, 1000)
        )['items'];

        $coatings = $this->buildCoatingOptions();

        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = [];
            try {
                $inputData = $request->getPayload()->all();
                $errors = $this->validator->validate($inputData, $this->mapper->getValidationCollection());
                if ($errors) {
                    throw new AppException(current($errors)->getFullMessage());
                }
                /** @var UpdateCoatingSystemMetadataCommand $command */
                $command = $this->mapper->buildCommandFromInputData($inputData, $id);
                $this->commandBus->execute($command);
                $this->addFlash('coating_system_updated_success', sprintf('Система покрытий "%s" обновлена.', $command->title));

                return $this->redirectToRoute('app_cabinet_coating_system_list');
            } catch (\Exception $e) {
                $error = $e->getMessage();

                return $this->render('cabinet/coating/coating_system/form.html.twig', [
                    'error' => $error,
                    'inputData' => $inputData,
                    'systemId' => $id,
                    'substrates' => Substrate::cases(),
                    'treatments' => $treatments,
                    'coatings' => $coatings,
                ]);
            }
        }

        $inputData = $this->mapper->buildInputDataFromDto($dto);

        return $this->render('cabinet/coating/coating_system/form.html.twig', [
            'inputData' => $inputData,
            'systemId' => $id,
            'substrates' => Substrate::cases(),
            'treatments' => $treatments,
            'coatings' => $coatings,
        ]);
    }

    /**
     * @return list<array{id: string, title: string, base_title: string, dft_min: int, dft_max: int}>
     */
    private function buildCoatingOptions(): array
    {
        $paginated = $this->coatingRepository->findByFilter(new CoatingsFilter());
        $options = [];
        foreach ($paginated->items as $coating) {
            $dft = $coating->getDftRange();
            $options[] = [
                'id' => $coating->getId(),
                'title' => $coating->getTitle(),
                'base_title' => $coating->getBase()->value,
                'dft_min' => (int) $dft->range->getMin(),
                'dft_max' => (int) $dft->range->getMax(),
            ];
        }

        return $options;
    }
}
