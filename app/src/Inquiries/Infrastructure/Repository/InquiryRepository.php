<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Repository;

use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Repository\InquiriesFilter;
use App\Inquiries\Domain\Repository\InquiriesSort;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Inquiry>
 */
class InquiryRepository extends ServiceEntityRepository implements InquiryRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Inquiry::class);
    }

    public function add(Inquiry $inquiry): void
    {
        $this->getEntityManager()->persist($inquiry);
        $this->getEntityManager()->flush();
    }

    public function remove(Inquiry $inquiry): void
    {
        $this->getEntityManager()->remove($inquiry);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Inquiry
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->find(Uuid::fromString($id));
    }

    public function findByFilter(InquiriesFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('i');

        if (null !== $filter->status) {
            $qb->andWhere('i.status = :status')->setParameter('status', $filter->status->value);
        }

        if ($filter->authorIds->count() > 0) {
            $qb->andWhere('i.ownerId IN (:authorIds)')->setParameter('authorIds', $filter->authorIds->getList());
        }

        if (null !== $filter->search && '' !== trim($filter->search)) {
            // ILIKE по телу запроса (вложения не храним — тут не ищем); регистронезависимо через LOWER.
            $qb->andWhere('LOWER(i.rawText) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower(trim($filter->search)).'%');
        }

        $qb->orderBy('i.createdAt', InquiriesSort::CREATED_ASC === $filter->sort ? 'ASC' : 'DESC');

        if (null !== $filter->pager) {
            $qb->setMaxResults($filter->pager->getLimit())->setFirstResult($filter->pager->getOffset());
        }

        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
    }
}
