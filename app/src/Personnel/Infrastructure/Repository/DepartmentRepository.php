<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Repository;

use App\Personnel\Domain\Aggregate\Department\Department;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Department>
 */
class DepartmentRepository extends ServiceEntityRepository implements DepartmentRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Department::class);
    }

    public function add(Department $department): void
    {
        $this->getEntityManager()->persist($department);
        $this->getEntityManager()->flush();
    }

    public function remove(Department $department): void
    {
        $this->getEntityManager()->remove($department);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Department
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function findByCompany(string $companyId): array
    {
        return $this->findBy(['companyId' => $companyId], ['title' => 'ASC']);
    }

    public function findChildren(string $parentId): array
    {
        return $this->findBy(['parentId' => $parentId], ['title' => 'ASC']);
    }

    /**
     * Итеративный подъём по parentId (не рекурсивный CTE): дерево отделов мелкое по глубине,
     * а обход через стандартный findOneById переиспользует identity map Doctrine и не требует
     * ручной гидрации сырых строк SQL. Защищён от цикла в данных счётчиком visited — по
     * инварианту DepartmentTreePolicy циклов быть не должно, но подъём не должен зависнуть,
     * если инвариант когда-то нарушат в обход домена (прямой SQL).
     */
    public function findAncestors(string $departmentId): array
    {
        $node = $this->findOneById($departmentId);
        if (null === $node) {
            return [];
        }

        $ancestors = [];
        $visited = [$node->getId() => true];
        $parentId = $node->getParentId();
        while (null !== $parentId) {
            if (isset($visited[$parentId])) {
                break;
            }
            $parent = $this->findOneById($parentId);
            if (null === $parent) {
                break;
            }
            $ancestors[] = $parent;
            $visited[$parent->getId()] = true;
            $parentId = $parent->getParentId();
        }

        return $ancestors;
    }

    public function findByIds(StringCollection $ids): array
    {
        if (0 === $ids->count()) {
            return [];
        }

        return array_values($this->findBy(['id' => $ids->getList()]));
    }
}
