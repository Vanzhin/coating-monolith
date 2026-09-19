<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Repository;

use App\Reports\Domain\Aggregate\Report\Report;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Reports\Domain\Repository\ReportsFilter;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Report>
 */
class ReportRepository extends ServiceEntityRepository implements ReportRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Report::class);
    }

    public function add(Report $report): void
    {
        $this->getEntityManager()->persist($report);
        $this->getEntityManager()->flush();
    }

    public function remove(Report $report): void
    {
        $this->getEntityManager()->remove($report);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Report
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->find(Uuid::fromString($id));
    }

    public function findByFilter(ReportsFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('r')->orderBy('r.updatedAt', 'DESC');

        if (null !== $filter->ownerId) {
            $qb->andWhere('r.ownerId = :owner')->setParameter('owner', $filter->ownerId);
        }
        if (null !== $filter->status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $filter->status->value);
        }
        if (null !== $filter->search && '' !== trim($filter->search)) {
            $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filter->search)).'%';
            $qb->andWhere('LOWER(r.actNumber) LIKE LOWER(:q) OR LOWER(r.projectTitle) LIKE LOWER(:q)')
                ->setParameter('q', $needle);
        }
        if (null !== $filter->pager) {
            $qb->setMaxResults($filter->pager->getLimit())->setFirstResult($filter->pager->getOffset());
        }

        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
    }
}
