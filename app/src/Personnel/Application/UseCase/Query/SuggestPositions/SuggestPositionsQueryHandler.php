<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\SuggestPositions;

use App\Personnel\Application\DTO\Position\PositionDTOTransformer;
use App\Personnel\Domain\Repository\PositionRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class SuggestPositionsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private PositionRepositoryInterface $repository,
        private PositionDTOTransformer $transformer,
    ) {
    }

    public function __invoke(SuggestPositionsQuery $query): SuggestPositionsQueryResult
    {
        $positions = $this->repository->suggest($query->query, $query->limit);

        return new SuggestPositionsQueryResult($this->transformer->fromEntityList($positions));
    }
}
