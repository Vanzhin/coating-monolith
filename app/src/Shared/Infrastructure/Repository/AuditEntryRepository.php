<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Repository;

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

    public function forClass(string $entityClass, ?string $actorId, Pager $pager): array
    {
        $qb = $this->base($pager)
            ->andWhere('a.entityClass = :c')->setParameter('c', $entityClass);
        if (null !== $actorId) {
            $qb->andWhere('a.actorId = :actor')->setParameter('actor', $actorId);
        }

        return $qb->getQuery()->getResult();
    }

    private function base(Pager $pager): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.seq', 'DESC')
            ->setFirstResult($pager->getOffset())
            ->setMaxResults($pager->getLimit());
    }
}
