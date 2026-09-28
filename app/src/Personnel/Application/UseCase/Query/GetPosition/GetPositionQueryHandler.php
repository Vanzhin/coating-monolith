<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetPosition;

use App\Personnel\Application\DTO\Position\PositionDTOTransformer;
use App\Personnel\Domain\Repository\PositionRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetPositionQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private PositionRepositoryInterface $repository,
        private PositionDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetPositionQuery $query): GetPositionQueryResult
    {
        $position = $this->repository->findOneById($query->id);

        return new GetPositionQueryResult(
            null !== $position ? $this->transformer->fromEntity($position) : null,
        );
    }
}
