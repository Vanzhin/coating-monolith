<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\SuggestDepartments;

use App\Personnel\Application\DTO\Department\DepartmentDTOTransformer;
use App\Personnel\Domain\Aggregate\Department\Department;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

final readonly class SuggestDepartmentsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private DepartmentRepositoryInterface $repository,
        private DepartmentDTOTransformer $transformer,
    ) {
    }

    public function __invoke(SuggestDepartmentsQuery $query): SuggestDepartmentsQueryResult
    {
        $paginator = $this->repository->findByFilter($query->filter);

        /** @var list<Department> $departments */
        $departments = array_values($paginator->items);
        $pager = $query->filter->pager ?? Pager::fromPage();

        return new SuggestDepartmentsQueryResult(
            $this->transformer->fromEntityList($departments),
            new Pager($pager->page, $pager->perPage, $paginator->total),
        );
    }
}
