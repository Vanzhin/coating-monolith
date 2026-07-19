<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Substance;

use App\ChemicalResistance\Application\UseCase\Query\GetPagedSubstances\GetPagedSubstancesQuery;
use App\ChemicalResistance\Domain\Repository\SubstancesFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/chemical-resistance/substance/list', name: 'app_cabinet_chemical_resistance_substance_list', methods: ['GET'])]
class ListAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus) {}

    public function __invoke(Request $request): Response
    {
        $search = $request->query->get('search');
        $page   = $request->query->get('page')  ? (int) $request->query->get('page')  : null;
        $limit  = $request->query->get('limit') ? (int) $request->query->get('limit') : null;

        $result = $this->queryBus->execute(
            new GetPagedSubstancesQuery(new SubstancesFilter($search, Pager::fromPage($page, $limit))),
        );

        return $this->render('admin/chemical_resistance/substance/index.html.twig', compact('result'));
    }
}
