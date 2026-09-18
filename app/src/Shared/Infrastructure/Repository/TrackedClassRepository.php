<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Repository;

use App\Shared\Domain\Audit\TrackedClass;
use App\Shared\Domain\Audit\TrackedClassRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TrackedClass> */
final class TrackedClassRepository extends ServiceEntityRepository implements TrackedClassRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackedClass::class);
    }

    public function findByClass(string $entityClass): ?TrackedClass
    {
        return $this->findOneBy(['entityClass' => $entityClass]);
    }

    public function all(): array
    {
        return $this->findBy([], ['entityClass' => 'ASC']);
    }

    public function save(TrackedClass $trackedClass): void
    {
        $this->getEntityManager()->persist($trackedClass);
        $this->getEntityManager()->flush();
    }

    public function remove(TrackedClass $trackedClass): void
    {
        $this->getEntityManager()->remove($trackedClass);
        $this->getEntityManager()->flush();
    }
}
