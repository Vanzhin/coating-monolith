<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Api\Coating;

use App\Coatings\Application\DTO\Coatings\CoatingSuggestDTO;
use App\Coatings\Application\UseCase\Query\SearchCoatings\SearchCoatingsQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatings\SearchCoatingsQueryResult;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/api/coatings',
    name: 'api_coatings_list',
    methods: ['GET'],
)]
class ListApiAction
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $q = $request->query->get('q');
        $limit = max(1, min(100, (int) $request->query->get('limit', 30)));

        /** @var SearchCoatingsQueryResult $result */
        $result = $this->queryBus->execute(new SearchCoatingsQuery(new CoatingsFilter(
            search: is_string($q) && '' !== $q ? SearchQuery::tryFromString($q) : null,
            pager: Pager::fromPage(1, $limit),
        )));

        $items = array_map(
            static fn (CoatingSuggestDTO $coating): array => [
                'id' => $coating->id,
                'title' => $coating->title,
                'base' => $coating->base,
                'dftMin' => $coating->dftMin,
                'dftMax' => $coating->dftMax,
            ],
            $result->coatings,
        );

        return new JsonResponse(
            ['items' => $items],
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        );
    }
}
