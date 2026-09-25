<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Counterparty;

use App\Reports\Application\UseCase\Query\SuggestCounterparties\SuggestCounterpartiesQuery;
use App\Reports\Application\UseCase\Query\SuggestCounterparties\SuggestCounterpartiesQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/reports/counterparty/suggest',
    name: 'app_cabinet_reports_counterparty_suggest',
    methods: ['GET'],
)]
// typeahead контрагентов для админ-формы отчёта; read-запрос не гейтим, ограничиваем эндпоинт.
#[IsGranted('ROLE_ADMIN')]
final class SuggestCounterpartiesAction extends AbstractController
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

        $result = $this->queryBus->execute(new SuggestCounterpartiesQuery($q, $limit));
        \assert($result instanceof SuggestCounterpartiesQueryResult);

        $items = array_map(
            static fn ($dto) => ['id' => $dto->id, 'title' => $dto->title, 'tin' => $dto->tin],
            $result->counterparties,
        );

        return new JsonResponse(['items' => $items]);
    }
}
