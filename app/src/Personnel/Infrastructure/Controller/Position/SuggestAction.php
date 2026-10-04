<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Position;

use App\Personnel\Application\UseCase\Query\SuggestPositions\SuggestPositionsQuery;
use App\Personnel\Application\UseCase\Query\SuggestPositions\SuggestPositionsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * typeahead должностей (напр. для формы профиля сотрудника). Read-запрос не гейтим —
 * просмотр справочника открыт всем авторизованным.
 */
#[Route(
    path: '/cabinet/personnel/position/suggest',
    name: 'app_cabinet_personnel_position_suggest',
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

        $result = $this->queryBus->execute(new SuggestPositionsQuery($q, $limit));
        \assert($result instanceof SuggestPositionsQueryResult);

        $items = array_map(
            static fn ($dto) => ['id' => $dto->id, 'title' => $dto->title],
            $result->positions,
        );

        return new JsonResponse(['items' => $items]);
    }
}
