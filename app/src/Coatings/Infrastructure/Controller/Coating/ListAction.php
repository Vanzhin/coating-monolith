<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQuery;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;
use App\Coatings\Infrastructure\Mapper\CoatingListRequestMapper;
use App\Coatings\Infrastructure\View\CoatingListViewFactory;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating/list', name: 'app_cabinet_coating_coating_list', methods: ['GET'])]
class ListAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CoatingListRequestMapper $requestMapper,
        private readonly CoatingListViewFactory $viewFactory,
        private readonly QueryParams $queryParams,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $error = null;
        try {
            $result = $this->queryBus->execute(
                new GetPagedCoatingsQuery($this->requestMapper->filterFromRequest($request)),
            );
        } catch (AppException $e) {
            $error = $e->getMessage();
            $result = new GetPagedCoatingsQueryResult([], Pager::fromPage(
                $this->queryParams->positiveInt($request, 'page'),
                $this->queryParams->positiveInt($request, 'limit'),
            ));
        }

        // Infinite scroll: AJAX-догрузка next-page отдаёт голый partial с карточками.
        if ($request->query->getBoolean('partial')) {
            return $this->render('admin/coating/coating/_coating_cards_batch.html.twig', [
                'coatings' => $result->coatings,
                'canEdit' => $this->isGranted('ROLE_ADMIN'),
                'selectedTagIdList' => $this->queryParams->stringCollection($request, 'tagIds')->getList(),
            ]);
        }

        return $this->render(
            'admin/coating/coating/index.html.twig',
            $this->viewFactory->build($request, $result, $error),
        );
    }
}
