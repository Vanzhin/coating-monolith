<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetPagedProfiles;

use App\Personnel\Application\DTO\Profile\ProfileDTOTransformer;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedProfilesQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProfileRepositoryInterface $repository,
        private ProfileDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetPagedProfilesQuery $query): GetPagedProfilesQueryResult
    {
        $paginator = $this->repository->findByFilter($query->filter);
        $profiles = $this->transformer->fromEntityList($paginator->items);
        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total,
        );

        return new GetPagedProfilesQueryResult($profiles, $pager);
    }
}
