<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Controller;

use App\Coatings\Application\UseCase\Command\UpdateCoating\UpdateCoatingCommand;
use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQuery;
use App\Coatings\Application\UseCase\Query\GetPagedCoatingTags\GetPagedCoatingTagsQuery;
use App\Coatings\Application\UseCase\Query\GetPagedManufacturers\GetPagedManufacturersQuery;
use App\Coatings\Domain\Aggregate\Coating\Coating;
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

#[Route(path: '/cabinet/proposals/{id}/edit', name: 'app_cabinet_proposals_general_proposal_update')]

class UpdateAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface   $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly Validator           $validator,
        private readonly CoatingMapper       $coatingMapper,
    )
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        dd($id);
        try {
            $queryManufacturers = new GetPagedManufacturersQuery(new ManufacturersFilter(null, Pager::fromPage(1, 1000)));
            $pagedManufacturers = $this->queryBus->execute($queryManufacturers);

            $queryTags = new GetPagedCoatingTagsQuery(new CoatingTagsFilter(Pager::fromPage(1, 1000), null, Coating::COAT_TYPE, Coating::PROTECTION_TYPE));
            $pagedCoatingTags = $this->queryBus->execute($queryTags);

            $queryCoating = new GetCoatingQuery($id);
            $coating = $this->queryBus->execute($queryCoating);
            if (!$coating->coatingDTO) {
                $this->addFlash('manufacturer_update_error', sprintf('Coating with id "%s" not found.', $id));
                return $this->redirectToRoute('app_cabinet_coating_coating_list');
            }

            $dto = $coating->coatingDTO;
            if ($request->isMethod(Request::METHOD_POST)) {

                $inputData = $request->getPayload()->all();
                $inputData['id'] = $id;
                $errors = $this->validator->validate($request->getPayload()->all(), $this->coatingMapper->getValidationCollectionCoating());
                if ($errors) {
                    throw new \Exception(current($errors)->getFullMessage());
                }
                $dto = $this->coatingMapper->buildCoatingDtoFromInputData($inputData);
                $command = new UpdateCoatingCommand($id, $dto);
                $this->commandBus->execute($command);
                $this->addFlash('manufacturer_updated_success', sprintf('Покрытие "%s" обновлено.', $dto->title));

                return $this->redirectToRoute('app_cabinet_coating_coating_list');
            }
        } catch (\Exception|\Error $e) {
            $error = $e->getMessage();
            return $this->render('admin/coating/coating/edit.html.twig', compact('error', 'dto', 'pagedManufacturers', 'pagedCoatingTags'));
        }

        return $this->render('admin/coating/coating/edit.html.twig', compact('pagedManufacturers', 'pagedCoatingTags', 'dto'));
    }
}