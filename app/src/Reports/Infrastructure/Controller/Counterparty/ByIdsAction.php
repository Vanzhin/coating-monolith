<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Counterparty;

use App\Reports\Application\DTO\Counterparties\CounterpartyDTO;
use App\Reports\Application\UseCase\Query\GetCounterpartiesByIds\GetCounterpartiesByIdsQuery;
use App\Reports\Application\UseCase\Query\GetCounterpartiesByIds\GetCounterpartiesByIdsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Восстановление чипов фасетов «Заказчик»/«Подрядчик» списка отчётов: {id,title} по списку id.
 * Зеркалит Coatings\Coating\ByIdsAction; id контрагента — uuid. Guard симметричен suggest;
 * при открытии фильтра обычным пользователям снять #[IsGranted] тут и на suggest.
 */
#[Route(
    path: '/cabinet/reports/counterparty/by-ids',
    name: 'app_cabinet_reports_counterparty_by_ids',
    methods: ['GET'],
)]
#[IsGranted('ROLE_ADMIN')]
final class ByIdsAction extends AbstractController
{
    private const MAX_IDS = 50;

    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $ids = array_values(array_filter(
            array_map('strval', $request->query->all('ids')),
            static fn (string $id): bool => Uuid::isValid($id),
        ));

        if ([] === $ids) {
            return new JsonResponse(['items' => []]);
        }

        $result = $this->queryBus->execute(
            new GetCounterpartiesByIdsQuery(new StringCollection(...array_slice($ids, 0, self::MAX_IDS))),
        );
        \assert($result instanceof GetCounterpartiesByIdsQueryResult);

        $items = array_map(
            static fn (CounterpartyDTO $dto): array => ['id' => $dto->id, 'title' => $dto->title],
            $result->counterparties,
        );

        return new JsonResponse(['items' => $items]);
    }
}
