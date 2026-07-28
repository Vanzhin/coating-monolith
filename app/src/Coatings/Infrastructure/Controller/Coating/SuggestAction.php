<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\SearchCoatings\SearchCoatingsQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/coating/coating/suggest',
    name: 'app_cabinet_coating_coating_suggest',
    methods: ['GET'],
)]
#[IsGranted('ROLE_ADMIN')]
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
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));

        if ('' === $q) {
            return new JsonResponse(['items' => []]);
        }

        /** @var list<array{id: string, title: string, base: string, dftMin: int, dftMax: int}> $raw */
        $raw = $this->queryBus->execute(new SearchCoatingsQuery($q, $limit));

        $items = array_map(
            static fn ($item) => [
                'id' => $item['id'],
                'title' => $item['title'],
                'base' => $item['base'],
                'dftMin' => $item['dftMin'],
                'dftMax' => $item['dftMax'],
            ],
            $raw,
        );

        return new JsonResponse(['items' => $items]);
    }
}
