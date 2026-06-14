<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Command\CreateCoating\CreateCoatingCommand;
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
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Validation\Validator;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating', name: 'app_cabinet_coating_coating_create')]
class AddAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface   $queryBus,
        private readonly Validator           $validator,
        private readonly CoatingMapper       $coatingMapper,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $pagedManufacturers = $this->queryBus->execute(
            new GetPagedManufacturersQuery(new ManufacturersFilter(null, Pager::fromPage(1, 1000))),
        );
        $pagedCoatingTags = $this->queryBus->execute(
            new GetPagedCoatingTagsQuery(new CoatingTagsFilter(Pager::fromPage(1, 1000), null, Coating::COAT_TYPE, Coating::PROTECTION_TYPE)),
        );

        if ($request->isMethod(Request::METHOD_POST)) {
            try {
                $inputData = $request->getPayload()->all();
                $errors = $this->validator->validate($inputData, $this->coatingMapper->getValidationCollectionCoating());
                if ($errors) {
                    throw new AppException(current($errors)->getFullMessage());
                }
                $dto = $this->coatingMapper->buildCoatingDtoFromInputData($inputData);
                $command = new CreateCoatingCommand($dto);
                $this->commandBus->execute($command);
                $this->addFlash('manufacturer_created_success', sprintf('Покрытие "%s" добавлено.', $dto->title));

                return $this->redirectToRoute('app_cabinet_coating_coating_list');
            } catch (Exception $e) {
                $error = $e->getMessage();
                return $this->render(
                    'admin/coating/coating/form.html.twig',
                    array_merge(compact('error', 'inputData', 'pagedManufacturers', 'pagedCoatingTags'), ['coatingBases' => CoatingBase::cases()]),
                );
            }
        }

        // Дублирование: ?from={id} — берём существующее покрытие и наполняем форму его данными
        $inputData = null;
        $duplicateFrom = $request->query->get('from');
        if ($duplicateFrom !== null) {
            $source = $this->queryBus->execute(new GetCoatingQuery($duplicateFrom));
            if ($source->coatingDTO !== null) {
                $inputData = $this->coatingMapper->buildInputDataFromDto($source->coatingDTO);
                unset($inputData['id']);
                $inputData['title'] = $inputData['title'] . ' (копия)';
            }
        }

        return $this->render(
            'admin/coating/coating/form.html.twig',
            array_merge(compact('inputData', 'pagedManufacturers', 'pagedCoatingTags'), ['coatingBases' => CoatingBase::cases()]),
        );
    }
}
