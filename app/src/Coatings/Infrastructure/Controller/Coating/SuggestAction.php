<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\SearchCoatings\SearchCoatingsQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatings\SearchCoatingsQueryResult;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Coatings\Infrastructure\Api\CoatingSuggestNormalizer;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coating/coating/suggest',
    name: 'app_cabinet_coating_coating_suggest',
    methods: ['GET'],
)]
final class SuggestAction extends AbstractController
{
    private const MAX_LIMIT = 25;
    private const DEFAULT_LIMIT = 10;

    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));

        if ('' === $q) {
            return new JsonResponse(['items' => [], 'page' => 1, 'hasMore' => false]);
        }

        /** @var SearchCoatingsQueryResult $result */
        $result = $this->queryBus->execute(new SearchCoatingsQuery(new CoatingsFilter(
            search: SearchQuery::tryFromString($q),
            pager: Pager::fromPage($page, $limit),
        )));

        $items = array_map([CoatingSuggestNormalizer::class, 'toArray'], $result->coatings);

        return new JsonResponse([
            'items' => $items,
            'page' => $result->pager->page,
            'hasMore' => $this->hasMore($result->pager),
        ]);
    }

    private function hasMore(Pager $pager): bool
    {
        return null !== $pager->total_pages && $pager->page < $pager->total_pages;
    }
}
