<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetPagedProjects;

use App\Reports\Application\DTO\Projects\ProjectDTOTransformer;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedProjectsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProjectRepositoryInterface $repository,
        private ProjectDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetPagedProjectsQuery $query): GetPagedProjectsQueryResult
    {
        $paginator = $this->repository->findByFilter($query->filter);
        $projects = $this->transformer->fromEntityList($paginator->items);
        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total,
        );

        return new GetPagedProjectsQueryResult($projects, $pager);
    }
}
