<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Api\Coating;

use App\Coatings\Application\UseCase\Query\SearchCoatings\SearchCoatingsQuery;
use App\Shared\Application\Query\QueryBusInterface;
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

        /** @var list<array{id: string, title: string, base: string, dftMin: int, dftMax: int}> $items */
        $items = $this->queryBus->execute(new SearchCoatingsQuery(
            q: is_string($q) && '' !== $q ? $q : null,
            limit: $limit,
        ));

        return new JsonResponse(
            ['items' => $items],
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        );
    }
}
