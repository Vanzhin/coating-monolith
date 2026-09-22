<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetCounterpartiesByIds;

use App\Reports\Application\DTO\Counterparties\CounterpartyDTOTransformer;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Гидрация чипов фасетов «Заказчик»/«Подрядчик»: {id,title} по списку id. В URL лежат только id
 * (shareable-ссылка), названия дотягиваются отсюда. Зеркалит suggest, но резолвит по id, не по строке.
 */
final readonly class GetCounterpartiesByIdsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CounterpartyRepositoryInterface $repository,
        private CounterpartyDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetCounterpartiesByIdsQuery $query): GetCounterpartiesByIdsQueryResult
    {
        return new GetCounterpartiesByIdsQueryResult(
            $this->transformer->fromEntityList($this->repository->findByIds($query->ids)),
        );
    }
}
