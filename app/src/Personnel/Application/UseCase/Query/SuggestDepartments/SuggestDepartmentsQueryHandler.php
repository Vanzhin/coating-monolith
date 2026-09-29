<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\SuggestDepartments;

use App\Personnel\Application\DTO\Department\DepartmentDTOTransformer;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class SuggestDepartmentsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private DepartmentRepositoryInterface $repository,
        private DepartmentDTOTransformer $transformer,
    ) {
    }

    public function __invoke(SuggestDepartmentsQuery $query): SuggestDepartmentsQueryResult
    {
        $departments = $this->repository->suggest($query->query, $query->limit);

        return new SuggestDepartmentsQueryResult($this->transformer->fromEntityList($departments));
    }
}
