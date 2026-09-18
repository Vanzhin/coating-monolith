<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\SuggestCounterparties;

use App\Reports\Application\DTO\Counterparties\CounterpartyDTOTransformer;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class SuggestCounterpartiesQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CounterpartyRepositoryInterface $repository,
        private CounterpartyDTOTransformer $transformer,
    ) {
    }

    public function __invoke(SuggestCounterpartiesQuery $query): SuggestCounterpartiesQueryResult
    {
        $counterparties = $this->repository->suggest($query->query, $query->limit);

        return new SuggestCounterpartiesQueryResult($this->transformer->fromEntityList($counterparties));
    }
}
