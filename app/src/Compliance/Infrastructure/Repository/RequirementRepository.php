<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Repository;

use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementsFilter;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Requirement>
 */
class RequirementRepository extends ServiceEntityRepository implements RequirementRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Requirement::class);
    }

    public function add(Requirement $requirement): void
    {
        $this->getEntityManager()->persist($requirement);
        $this->getEntityManager()->flush();
    }

    public function remove(Requirement $requirement): void
    {
        $this->getEntityManager()->remove($requirement);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Requirement
    {
        return $this->find($id);
    }

    public function findByFilter(RequirementsFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('r')->orderBy('r.name', 'ASC');
        if (null !== $filter->pager) {
            $qb->setMaxResults($filter->pager->getLimit());
            $qb->setFirstResult($filter->pager->getOffset());
        }
        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
    }
}
