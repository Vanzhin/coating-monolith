<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Repository;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Audit\AuditEntry;
use App\Shared\Domain\Audit\AuditEntryRepositoryInterface;
use App\Shared\Domain\Repository\Pager;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditEntry> */
final class AuditEntryRepository extends ServiceEntityRepository implements AuditEntryRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEntry::class);
    }

    public function forEntity(string $entityClass, string $entityId, Pager $pager): array
    {
        return $this->base($pager)
            ->andWhere('a.entityClass = :c')->setParameter('c', $entityClass)
            ->andWhere('a.entityId = :id')->setParameter('id', $entityId)
            ->getQuery()->getResult();
    }

    public function forClass(string $entityClass, StringCollection $actorIds, StringCollection $entityIds, Pager $pager): array
    {
        $qb = $this->base($pager)
            ->andWhere('a.entityClass = :c')->setParameter('c', $entityClass);
        $this->applyActorFacet($qb, $actorIds);
        $this->applyEntityFacet($qb, $entityIds);

        return $qb->getQuery()->getResult();
    }

    private function applyActorFacet(QueryBuilder $qb, StringCollection $actorIds): void
    {
        if (0 === $actorIds->count()) {
            return;
        }
        $qb->andWhere('a.actorId IN (:actors)')->setParameter('actors', $actorIds->getList());
    }

    private function applyEntityFacet(QueryBuilder $qb, StringCollection $entityIds): void
    {
        if (0 === $entityIds->count()) {
            return;
        }
        $qb->andWhere('a.entityId IN (:ids)')->setParameter('ids', $entityIds->getList());
    }

    private function base(Pager $pager): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.seq', 'DESC')
            ->setFirstResult($pager->getOffset())
            ->setMaxResults($pager->getLimit());
    }
}
