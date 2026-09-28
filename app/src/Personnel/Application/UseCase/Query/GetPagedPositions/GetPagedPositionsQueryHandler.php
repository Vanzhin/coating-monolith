<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetPagedPositions;

use App\Personnel\Application\DTO\Position\PositionDTOTransformer;
use App\Personnel\Domain\Repository\PositionRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedPositionsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private PositionRepositoryInterface $repository,
        private PositionDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetPagedPositionsQuery $query): GetPagedPositionsQueryResult
    {
        $paginator = $this->repository->findByFilter($query->filter);
        $positions = $this->transformer->fromEntityList($paginator->items);
        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total,
        );

        return new GetPagedPositionsQueryResult($positions, $pager);
    }
}
