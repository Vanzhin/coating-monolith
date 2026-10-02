<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfileIdsByFilter;

use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Personnel\Domain\Repository\ProfilesFilter;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetProfileIdsByFilterQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ProfileRepositoryInterface $repository)
    {
    }

    public function __invoke(GetProfileIdsByFilterQuery $query): GetProfileIdsByFilterQueryResult
    {
        $ids = $this->repository->findIdsByFilter(new ProfilesFilter(
            positionIds: $query->positionIds,
            search: $query->search,
        ));

        return new GetProfileIdsByFilterQueryResult($ids);
    }
}
