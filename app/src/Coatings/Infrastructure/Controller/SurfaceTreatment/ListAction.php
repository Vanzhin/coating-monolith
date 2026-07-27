<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\SurfaceTreatment;

use App\Coatings\Application\UseCase\Query\ListSurfaceTreatments\ListSurfaceTreatmentsQuery;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\SurfaceTreatmentsFilter;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/surface-treatment/list', name: 'app_cabinet_surface_treatment_list', methods: ['GET'])]
class ListAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max(1, (int) ($request->query->get('page') ?? 1));
        $perPage = 20;

        $q = $request->query->get('q') ?: null;
        $substrateRaw = $request->query->get('substrate');
        $substrate = is_string($substrateRaw) ? Substrate::tryFrom($substrateRaw) : null;

        $filter = new SurfaceTreatmentsFilter(
            substrate: $substrate,
            q: $q,
        );

        $result = $this->queryBus->execute(new ListSurfaceTreatmentsQuery($filter, $page, $perPage));

        return $this->render('cabinet/coating/surface_treatment/list.html.twig', [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
            'q' => $q ?? '',
            'substrate' => $substrateRaw ?? '',
            'substrates' => Substrate::cases(),
        ]);
    }
}
