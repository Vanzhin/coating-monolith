<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Repository;

use App\Personnel\Domain\Aggregate\Profile\Profile;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Personnel\Domain\Repository\ProfilesFilter;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Profile>
 */
class ProfileRepository extends ServiceEntityRepository implements ProfileRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Profile::class);
    }

    public function add(Profile $profile): void
    {
        $this->getEntityManager()->persist($profile);
        $this->getEntityManager()->flush();
    }

    public function remove(Profile $profile): void
    {
        $this->getEntityManager()->remove($profile);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Profile
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function findOneByUserUlid(string $userUlid): ?Profile
    {
        return $this->findOneBy(['userUlid' => $userUlid]);
    }

    public function findByFilter(ProfilesFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('p')->orderBy('p.createdAt', 'DESC');
        $this->applyFacets($qb, $filter);

        if (null !== $filter->pager) {
            $qb->setMaxResults($filter->pager->getLimit());
            $qb->setFirstResult($filter->pager->getOffset());
        }

        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
    }

    public function findIdsByFilter(ProfilesFilter $filter): StringCollection
    {
        $qb = $this->createQueryBuilder('p')->select('p.id');
        $this->applyFacets($qb, $filter);

        /** @var list<array{id: string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return new StringCollection(...array_map(static fn (array $r): string => (string) $r['id'], $rows));
    }

    private function applyFacets(\Doctrine\ORM\QueryBuilder $qb, ProfilesFilter $filter): void
    {
        // Фасеты — id внутри jsonb-снимков (OR внутри фасета, AND между собой).
        if ($filter->positionIds->count() > 0) {
            $qb->andWhere("JSONB_GET_TEXT(p.position, 'id') IN (:positionIds)")
                ->setParameter('positionIds', $filter->positionIds->getList());
        }
        if ($filter->organizationIds->count() > 0) {
            $qb->andWhere("JSONB_GET_TEXT(p.organization, 'id') IN (:organizationIds)")
                ->setParameter('organizationIds', $filter->organizationIds->getList());
        }
        if ($filter->departmentIds->count() > 0) {
            $qb->andWhere("JSONB_GET_TEXT(p.department, 'id') IN (:departmentIds)")
                ->setParameter('departmentIds', $filter->departmentIds->getList());
        }
        if (null !== $filter->search && '' !== trim($filter->search)) {
            $needle = '%'.$this->escapeLike(trim($filter->search)).'%';
            $qb->andWhere(
                "LOWER(JSONB_GET_TEXT(p.fullName, 'lastName')) LIKE LOWER(:q)"
                ." OR LOWER(JSONB_GET_TEXT(p.fullName, 'firstName')) LIKE LOWER(:q)"
                ." OR LOWER(JSONB_GET_TEXT(p.fullName, 'middleName')) LIKE LOWER(:q)",
            )->setParameter('q', $needle);
        }
    }

    public function countByPositionId(string $positionId): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere("JSONB_GET_TEXT(p.position, 'id') = :positionId")
            ->setParameter('positionId', $positionId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByDepartmentId(string $departmentId): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere("JSONB_GET_TEXT(p.department, 'id') = :departmentId")
            ->setParameter('departmentId', $departmentId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByIds(StringCollection $ids): array
    {
        if (0 === $ids->count()) {
            return [];
        }

        return array_values($this->findBy(['id' => $ids->getList()]));
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
