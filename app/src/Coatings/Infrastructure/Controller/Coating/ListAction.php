<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQuery;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;
use App\Coatings\Application\UseCase\Query\GetPagedManufacturers\GetPagedManufacturersQuery;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\ManufacturersFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating/list', name: 'app_cabinet_coating_coating_list', methods: ['GET'])]
class ListAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $search = $request->query->get('search');
        $manufacturerIds = new StringCollection(...$request->query->all('manufacturerIds'));
        $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
        $limit = $request->query->get('limit') ? (int) $request->query->get('limit') : null;
        $pager = Pager::fromPage($page, $limit);

        $manufacturersResult = $this->queryBus->execute(
            new GetPagedManufacturersQuery(new ManufacturersFilter(null, Pager::fromPage(1, 1000))),
        );

        $error = null;
        try {
            $filter = new CoatingsFilter(
                search: $search,
                manufacturerIds: $manufacturerIds,
                pager: $pager,
            );
            $result = $this->queryBus->execute(new GetPagedCoatingsQuery($filter));
        } catch (AppException $e) {
            $error = $e->getMessage();
            $result = new GetPagedCoatingsQueryResult([], $pager);
        }

        return $this->render('admin/coating/coating/index.html.twig', [
            'search' => $search ?? '',
            'selectedManufacturerIds' => $manufacturerIds,
            'manufacturers' => $manufacturersResult->manufacturers,
            'result' => $result,
            'error' => $error,
            'coatingBases' => CoatingBase::cases(),
        ]);
    }
}
