<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Repository;

use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Certificates\Domain\Repository\IssuersFilter;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class IssuerRepository extends ServiceEntityRepository implements IssuerRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Issuer::class);
    }

    public function add(Issuer $issuer): void
    {
        $this->getEntityManager()->persist($issuer);
        $this->getEntityManager()->flush();
    }

    public function remove(Issuer $issuer): void
    {
        $this->getEntityManager()->remove($issuer);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Issuer
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function findOneByTitle(string $title): ?Issuer
    {
        return $this->findOneBy(['title' => $title]);
    }

    public function findByIds(StringCollection $ids): array
    {
        if (0 === $ids->count()) {
            return [];
        }

        return $this->findBy(['id' => $ids->getList()]);
    }

    public function findByFilter(IssuersFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('i')->orderBy('i.title', 'ASC');
        if (null !== $filter->title && '' !== trim($filter->title)) {
            $qb->andWhere('LOWER(i.title) LIKE LOWER(:title)')
                ->setParameter('title', '%'.$this->escapeLike(trim($filter->title)).'%');
        }
        if (null !== $filter->pager) {
            $qb->setMaxResults($filter->pager->getLimit());
            $qb->setFirstResult($filter->pager->getOffset());
        }
        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
    }

    public function suggest(string $query, int $limit = 10): array
    {
        $needle = trim($query);
        if ('' === $needle) {
            return [];
        }

        return $this->createQueryBuilder('i')
            ->where('LOWER(i.title) LIKE LOWER(:q)')
            ->setParameter('q', $this->escapeLike($needle).'%')
            ->orderBy('i.title', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
