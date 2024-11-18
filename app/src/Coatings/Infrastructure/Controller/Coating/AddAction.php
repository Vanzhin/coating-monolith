<?php
declare(strict_types=1);


namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Command\CreateCoating\CreateCoatingCommand;
use App\Coatings\Application\UseCase\Query\GetPagedCoatingTags\GetPagedCoatingTagsQuery;
use App\Coatings\Application\UseCase\Query\GetPagedManufacturers\GetPagedManufacturersQuery;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Repository\CoatingTagsFilter;
use App\Coatings\Domain\Repository\ManufacturersFilter;
use App\Coatings\Infrastructure\Mapper\CoatingMapper;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Validation\Validator;
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

    )
    {
    }

    public function __invoke(Request $request): Response
    {
        $queryManufacturers = new GetPagedManufacturersQuery(new ManufacturersFilter(null, Pager::fromPage(1, 1000)));
        $pagedManufacturers = $this->queryBus->execute($queryManufacturers);

        $queryTags = new GetPagedCoatingTagsQuery(new CoatingTagsFilter(Pager::fromPage(1, 1000), null, Coating::COAT_TYPE, Coating::PROTECTION_TYPE));
        $pagedCoatingTags = $this->queryBus->execute($queryTags);
        dd($pagedCoatingTags);
        if ($request->isMethod(Request::METHOD_POST)) {
            try {
                $inputData = $request->getPayload()->all();
                $errors = $this->validator->validate($request->getPayload()->all(), $this->coatingMapper->getValidationCollectionCoating());
                if ($errors) {
                    throw new \Exception(current($errors)->getFullMessage());
                }
                extract($inputData);
                $command = new CreateCoatingCommand(
                    $description, $title, (int)$volumeSolid, (int)$massDensity, (int)$tdsDft, (int)$minDft, (int)$maxDft,
                    (int)$applicationMinTemp, (int)$dryToTouch, (int)$minRecoatingInterval, (int)$maxRecoatingInterval,
                    (int)$fullCure, $manufacturerId
                );
                $this->commandBus->execute($command);
                $this->addFlash('manufacturer_created_success', sprintf('Покрытие "%s" добавлено.', $title));

                return $this->redirectToRoute('app_cabinet_coating_coating_list');
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
                return $this->render('admin/coating/coating/create.html.twig', compact('error', 'inputData'));
            }
        }

        return $this->render('admin/coating/coating/create.html.twig', compact('pagedManufacturers'));
    }
}