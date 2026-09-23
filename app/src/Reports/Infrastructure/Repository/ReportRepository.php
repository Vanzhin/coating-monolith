<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Repository;

use App\Reports\Domain\Aggregate\Report\Report;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Reports\Domain\Repository\ReportsFilter;
use App\Reports\Domain\Repository\ReportsSort;
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
        $qb = $this->createQueryBuilder('r');

        // owner — скалярная колонка; заказчик/подрядчик/проект — id внутри JSONB Reference-колонок.
        // Внутри фасета OR (IN), между фасетами AND. Пустой StringCollection = фасет не задан.
        if ($filter->ownerIds->count() > 0) {
            $qb->andWhere('r.ownerId IN (:ownerIds)')->setParameter('ownerIds', $filter->ownerIds->getList());
        }
        if ($filter->customerIds->count() > 0) {
            $qb->andWhere("JSONB_GET_TEXT(r.customer, 'id') IN (:customerIds)")
                ->setParameter('customerIds', $filter->customerIds->getList());
        }
        if ($filter->contractorIds->count() > 0) {
            $qb->andWhere("JSONB_GET_TEXT(r.contractor, 'id') IN (:contractorIds)")
                ->setParameter('contractorIds', $filter->contractorIds->getList());
        }
        if ($filter->projectIds->count() > 0) {
            $qb->andWhere("JSONB_GET_TEXT(r.project, 'id') IN (:projectIds)")
                ->setParameter('projectIds', $filter->projectIds->getList());
        }
        if (null !== $filter->type) {
            $qb->andWhere('r.type = :type')->setParameter('type', $filter->type->value);
        }
        if (null !== $filter->status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $filter->status->value);
        }
        if (null !== $filter->search && '' !== trim($filter->search)) {
            $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filter->search)).'%';
            $qb->andWhere("LOWER(r.actNumber) LIKE LOWER(:q) OR LOWER(JSONB_GET_TEXT(r.project, 'title')) LIKE LOWER(:q)")
                ->setParameter('q', $needle);
        }

        // Сортировка (дефолт — по дате создания, сначала новые).
        match ($filter->sort) {
            ReportsSort::DEFAULT => $qb->orderBy('r.createdAt', 'DESC'),
            ReportsSort::CREATED_ASC => $qb->orderBy('r.createdAt', 'ASC'),
            ReportsSort::UPDATED_DESC => $qb->orderBy('r.updatedAt', 'DESC'),
        };

        if (null !== $filter->pager) {
            $qb->setMaxResults($filter->pager->getLimit())->setFirstResult($filter->pager->getOffset());
        }

        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
    }
}
