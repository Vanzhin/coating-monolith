<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Coatings\Infrastructure\Mapper\CoatingSystemListRequestMapper;
use App\Coatings\Infrastructure\View\CoatingSystemListViewFactory;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    path: '/cabinet/coating/coating-system/list',
    name: 'app_cabinet_coating_system_list',
    methods: ['GET'],
)]
final class ListAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CoatingSystemListRequestMapper $requestMapper,
        private readonly CoatingSystemListViewFactory $viewFactory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var SearchCoatingSystemsQueryResult $result */
        $result = $this->queryBus->execute(
            new SearchCoatingSystemsQuery($this->requestMapper->filterFromRequest($request)),
        );

        // partial (догрузка «Показать ещё») рендерит батч карточек напрямую — ему нужен canEdit,
        // иначе кебаб на подгруженных карточках скрыт (на первой странице его даёт list.html.twig).
        if ($request->query->getBoolean('partial')) {
            return $this->render('cabinet/coating/coating_system/_list_cards.html.twig', [
                'items' => $result->items,
                'canEdit' => $this->isGranted('ROLE_ADMIN'),
            ]);
        }

        return $this->render('cabinet/coating/coating_system/list.html.twig', $this->viewFactory->build($request, $result));
    }
}
