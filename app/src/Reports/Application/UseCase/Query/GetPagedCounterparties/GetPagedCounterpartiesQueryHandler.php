<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetPagedCounterparties;

use App\Reports\Application\DTO\Counterparties\CounterpartyDTOTransformer;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedCounterpartiesQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CounterpartyRepositoryInterface $repository,
        private CounterpartyDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetPagedCounterpartiesQuery $query): GetPagedCounterpartiesQueryResult
    {
        $paginator = $this->repository->findByFilter($query->filter);
        $counterparties = $this->transformer->fromEntityList($paginator->items);
        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total,
        );

        return new GetPagedCounterpartiesQueryResult($counterparties, $pager);
    }
}
