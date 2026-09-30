<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfileIdsByPositions;

use App\Personnel\Domain\Aggregate\Profile\Profile;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Personnel\Domain\Repository\ProfilesFilter;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetProfileIdsByPositionsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ProfileRepositoryInterface $repository)
    {
    }

    public function __invoke(GetProfileIdsByPositionsQuery $query): GetProfileIdsByPositionsQueryResult
    {
        if (0 === $query->positionIds->count()) {
            return new GetProfileIdsByPositionsQueryResult([]);
        }

        $result = $this->repository->findByFilter(new ProfilesFilter(positionIds: $query->positionIds));

        return new GetProfileIdsByPositionsQueryResult(array_values(array_map(
            static fn (Profile $p): string => $p->getId(),
            $result->items,
        )));
    }
}
