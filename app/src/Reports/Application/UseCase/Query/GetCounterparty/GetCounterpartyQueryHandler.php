<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetCounterparty;

use App\Reports\Application\DTO\Counterparties\CounterpartyDTOTransformer;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetCounterpartyQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CounterpartyRepositoryInterface $repository,
        private CounterpartyDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetCounterpartyQuery $query): GetCounterpartyQueryResult
    {
        $counterparty = $this->repository->findOneById($query->id);

        return new GetCounterpartyQueryResult(
            null !== $counterparty ? $this->transformer->fromEntity($counterparty) : null,
        );
    }
}
