<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetCompanyDepartmentTree;

use App\Personnel\Application\DTO\Department\DepartmentNodeDTO;
use App\Personnel\Domain\Aggregate\Department\Department;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Собирает дерево отделов компании из плоского списка (findByCompany уже сортирует по title).
 * Узел с parentId, не найденным среди отделов той же компании (данные испорчены в обход
 * домена — по инварианту DepartmentTreePolicy такого быть не должно), считается корнем,
 * чтобы не потерять его в выдаче.
 */
final readonly class GetCompanyDepartmentTreeQueryHandler implements QueryHandlerInterface
{
    public function __construct(private DepartmentRepositoryInterface $repository)
    {
    }

    public function __invoke(GetCompanyDepartmentTreeQuery $query): GetCompanyDepartmentTreeQueryResult
    {
        $departments = $this->repository->findByCompany($query->companyId);

        /** @var array<string, DepartmentNodeDTO> $nodesById */
        $nodesById = [];
        foreach ($departments as $department) {
            $nodesById[$department->getId()] = $this->toNode($department);
        }

        $roots = [];
        foreach ($departments as $department) {
            $node = $nodesById[$department->getId()];
            $parentId = $department->getParentId();

            if (null !== $parentId && isset($nodesById[$parentId])) {
                $nodesById[$parentId]->children[] = $node;
            } else {
                $roots[] = $node;
            }
        }

        return new GetCompanyDepartmentTreeQueryResult($roots);
    }

    private function toNode(Department $department): DepartmentNodeDTO
    {
        $node = new DepartmentNodeDTO();
        $node->id = $department->getId();
        $node->title = $department->getTitle();
        $node->headUserUlid = $department->getHeadUserUlid();

        return $node;
    }
}
