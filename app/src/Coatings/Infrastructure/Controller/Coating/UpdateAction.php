<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\Service\GeneralTagsJsonHydrator;
use App\Coatings\Application\UseCase\Command\UpdateCoating\UpdateCoatingCommand;
use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQuery;
use App\Coatings\Application\UseCase\Query\GetPagedCoatingTags\GetPagedCoatingTagsQuery;
use App\Coatings\Application\UseCase\Query\GetPagedManufacturers\GetPagedManufacturersQuery;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Repository\CoatingTagsFilter;
use App\Coatings\Domain\Repository\ManufacturersFilter;
use App\Coatings\Infrastructure\Mapper\CoatingMapper;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating/{id}/edit', name: 'app_cabinet_coating_coating_update')]
class UpdateAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly Validator $validator,
        private readonly CoatingMapper $coatingMapper,
        private readonly GeneralTagsJsonHydrator $hydrator,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $pagedManufacturers = $this->queryBus->execute(
            new GetPagedManufacturersQuery(new ManufacturersFilter(null, Pager::fromPage(1, 1000))),
        );
        $pagedCoatingTags = $this->queryBus->execute(
            new GetPagedCoatingTagsQuery(new CoatingTagsFilter(Pager::fromPage(1, 1000), null, Coating::COAT_TYPE, Coating::PROTECTION_TYPE)),
        );

        $coating = $this->queryBus->execute(new GetCoatingQuery($id));
        if (!$coating->coatingDTO) {
            $this->addFlash('manufacturer_update_error', sprintf('Coating with id "%s" not found.', $id));

            return $this->redirectToRoute('app_cabinet_coating_coating_list');
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            try {
                $inputData = $request->getPayload()->all();
                $inputData['id'] = $id;
                $errors = $this->validator->validate($inputData, $this->coatingMapper->getValidationCollectionCoating());
                if ($errors) {
                    throw new \Exception(current($errors)->getFullMessage());
                }
                $dto = $this->coatingMapper->buildCoatingDtoFromInputData($inputData);
                $this->commandBus->execute(new UpdateCoatingCommand($id, $dto));
                $this->addFlash('manufacturer_updated_success', sprintf('Покрытие "%s" обновлено.', $dto->title));

                return $this->redirectToRoute('app_cabinet_coating_coating_list');
            } catch (\Exception $e) {
                $error = $e->getMessage();

                return $this->render('admin/coating/coating/form.html.twig', array_merge(
                    compact('error', 'inputData', 'pagedManufacturers', 'pagedCoatingTags'),
                    [
                        'coatingBases' => CoatingBase::cases(),
                        'existingTagsJson' => $this->hydrator->hydrateAsJson($inputData['tags'] ?? []),
                    ],
                ));
            }
        }

        $inputData = $this->coatingMapper->buildInputDataFromDto($coating->coatingDTO);

        return $this->render('admin/coating/coating/form.html.twig', array_merge(
            compact('inputData', 'pagedManufacturers', 'pagedCoatingTags'),
            [
                'coatingBases' => CoatingBase::cases(),
                'existingTagsJson' => $this->hydrator->hydrateAsJson($coating->coatingDTO->tags),
            ],
        ));
    }
}
