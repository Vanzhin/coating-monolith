<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\SuggestProjects;

use App\Reports\Application\DTO\Projects\ProjectDTOTransformer;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class SuggestProjectsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProjectRepositoryInterface $repository,
        private ProjectDTOTransformer $transformer,
    ) {
    }

    public function __invoke(SuggestProjectsQuery $query): SuggestProjectsQueryResult
    {
        $projects = $this->repository->suggest($query->query, $query->limit);

        return new SuggestProjectsQueryResult($this->transformer->fromEntityList($projects));
    }
}
