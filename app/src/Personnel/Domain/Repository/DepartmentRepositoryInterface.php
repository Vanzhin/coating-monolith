<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Repository;

use App\Personnel\Domain\Aggregate\Department\Department;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

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
     * Typeahead по названию (для гидрации/выбора отдела, напр. в будущей форме профиля
     * сотрудника). Без company-скоупа — зеркалит PositionRepositoryInterface::suggest.
     *
     * @return list<Department>
     */
    public function suggest(string $query, int $limit = 10): array;
}
