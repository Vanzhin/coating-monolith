<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\UseCase\Query\ListCoatingSystems\ListCoatingSystemsQuery;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating-system/list', name: 'app_cabinet_coating_system_list', methods: ['GET'])]
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

        $titleLike = $request->query->get('search') ?: null;
        $substrateRaw = $request->query->get('substrate');
        $substrate = is_string($substrateRaw) ? Substrate::tryFrom($substrateRaw) : null;

        $filter = new CoatingSystemsFilter(
            titleLike: $titleLike,
            substrate: $substrate,
        );

        $result = $this->queryBus->execute(new ListCoatingSystemsQuery($filter, $page, $perPage));

        return $this->render('cabinet/coating/coating_system/list.html.twig', [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
            'search' => $titleLike ?? '',
            'substrate' => $substrateRaw ?? '',
            'substrates' => Substrate::cases(),
        ]);
    }
}
