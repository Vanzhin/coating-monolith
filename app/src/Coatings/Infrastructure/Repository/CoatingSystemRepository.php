<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

final class CoatingSystemRepository implements CoatingSystemRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(CoatingSystem $system): void
    {
        $this->em->persist($system);
        $this->em->flush();
    }

    public function remove(CoatingSystem $system): void
    {
        $this->em->remove($system);
        $this->em->flush();
    }

    public function findById(Uuid $id): ?CoatingSystem
    {
        return $this->em->find(CoatingSystem::class, $id);
    }

    public function list(CoatingSystemsFilter $filter, int $limit, int $offset): array
    {
        $qb = $this->em->createQueryBuilder()->select('s')->from(CoatingSystem::class, 's');
        $this->applyFilter($qb, $filter);
        $qb->orderBy('s.updatedAt', 'DESC')->setFirstResult($offset)->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    public function count(CoatingSystemsFilter $filter): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(s.id)')->from(CoatingSystem::class, 's');
        $this->applyFilter($qb, $filter);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    // implemented, tested in Task 8
    public function findByCompliance(
        ComplianceStandard $standard,
        string $category,
        string $durability,
        ?Substrate $substrate,
        int $limit,
        int $offset,
    ): array {
        $sql = <<<SQL
            SELECT s.id FROM coating_system s
            INNER JOIN coating_system_compliance c ON c.system_id = s.id
            WHERE c.standard = :standard AND c.category = :category AND c.durability = :durability
            SQL;
        $params = [
            'standard' => $standard->value,
            'category' => $category,
            'durability' => $durability,
        ];
        if (null !== $substrate) {
            $sql .= ' AND s.substrate = :substrate';
            $params['substrate'] = $substrate->value;
        }
        $sql .= ' ORDER BY s.updated_at DESC LIMIT :limit OFFSET :offset';
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $ids = array_column($this->em->getConnection()->fetchAllAssociative($sql, $params), 'id');
        if ([] === $ids) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('s')->from(CoatingSystem::class, 's')
            ->where('s.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getResult();
    }

    public function countByCompliance(
        ComplianceStandard $standard,
        string $category,
        string $durability,
        ?Substrate $substrate,
    ): int {
        $sql = <<<SQL
            SELECT COUNT(*) FROM coating_system s
            INNER JOIN coating_system_compliance c ON c.system_id = s.id
            WHERE c.standard = :standard AND c.category = :category AND c.durability = :durability
            SQL;
        $params = [
            'standard' => $standard->value,
            'category' => $category,
            'durability' => $durability,
        ];
        if (null !== $substrate) {
            $sql .= ' AND s.substrate = :substrate';
            $params['substrate'] = $substrate->value;
        }

        return (int) $this->em->getConnection()->fetchOne($sql, $params);
    }

    public function findComplianceRows(Uuid $systemId): array
    {
        /** @var list<array{standard: string, category: string, durability: string}> */
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT standard, category, durability FROM coating_system_compliance WHERE system_id = ?',
            [$systemId->toRfc4122()],
        );
    }

    private function applyFilter(QueryBuilder $qb, CoatingSystemsFilter $filter): void
    {
        if (null !== $filter->titleLike && '' !== $filter->titleLike) {
            $qb->andWhere('LOWER(s.title) LIKE LOWER(:t)')->setParameter('t', '%'.$filter->titleLike.'%');
        }
        if (null !== $filter->substrate) {
            $qb->andWhere('s.substrate = :sub')->setParameter('sub', $filter->substrate->value);
        }
    }
}
