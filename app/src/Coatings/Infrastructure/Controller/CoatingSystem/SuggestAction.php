<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemTitleDTO;
use App\Coatings\Application\UseCase\Query\SuggestCoatingSystems\SuggestCoatingSystemsQuery;
use App\Coatings\Application\UseCase\Query\SuggestCoatingSystems\SuggestCoatingSystemsQueryResult;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    path: '/cabinet/coating/coating-system/suggest',
    name: 'app_cabinet_coating_system_suggest',
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
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));

        if ('' === $q) {
            return new JsonResponse(['items' => []]);
        }

        /** @var SuggestCoatingSystemsQueryResult $result */
        $result = $this->queryBus->execute(new SuggestCoatingSystemsQuery(new CoatingSystemsFilter(
            search: SearchQuery::tryFromString($q),
            pager: Pager::fromPage(1, $limit),
        )));

        $items = array_map(
            static fn (CoatingSystemTitleDTO $system): array => ['id' => $system->id, 'title' => $system->title],
            $result->items,
        );

        return new JsonResponse(['items' => $items]);
    }
}
