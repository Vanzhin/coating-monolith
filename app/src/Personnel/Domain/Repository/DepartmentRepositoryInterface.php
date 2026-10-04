<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Repository;

use App\Personnel\Domain\Aggregate\Department\Department;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;

interface DepartmentRepositoryInterface
{
    public function add(Department $department): void;

    public function remove(Department $department): void;

    public function findOneById(string $id): ?Department;

    /**
     * @return list<Department>
     */
    public function findByCompany(string $companyId): array;

    /**
     * @return list<Department>
     */
    public function findChildren(string $parentId): array;

    /**
     * Цепочка предков узла — от непосредственного родителя до корня, порядок снизу вверх
     * (первый элемент — родитель, последний — корень). Сам узел в цепочку НЕ входит.
     * Пустой массив — узел не найден или является корнем.
     *
     * @return list<Department>
     */
    public function findAncestors(string $departmentId): array;

    /**
     * @return list<Department>
     */
    public function findByIds(StringCollection $ids): array;

    /**
     * Единый поиск/список отделов через фильтр с пагинацией (typeahead и постраничный список).
     * Расширяется полями DepartmentsFilter (title, companyId-скоуп), а не новыми методами.
     */
    public function findByFilter(DepartmentsFilter $filter): PaginationResult;
}
