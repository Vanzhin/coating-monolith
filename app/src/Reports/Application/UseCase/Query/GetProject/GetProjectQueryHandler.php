<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetProject;

use App\Reports\Application\DTO\Projects\ProjectDTOTransformer;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetProjectQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProjectRepositoryInterface $repository,
        private ProjectDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetProjectQuery $query): GetProjectQueryResult
    {
        $project = $this->repository->findOneById($query->id);

        return new GetProjectQueryResult(
            null !== $project ? $this->transformer->fromEntity($project) : null,
        );
    }
}
