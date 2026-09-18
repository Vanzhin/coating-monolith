<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Repository;

use App\Reports\Domain\Aggregate\Counterparty\Counterparty;
use App\Reports\Domain\Repository\CounterpartiesFilter;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Counterparty>
 */
class CounterpartyRepository extends ServiceEntityRepository implements CounterpartyRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Counterparty::class);
    }

    public function add(Counterparty $counterparty): void
    {
        $this->getEntityManager()->persist($counterparty);
        $this->getEntityManager()->flush();
    }

    public function remove(Counterparty $counterparty): void
    {
        $this->getEntityManager()->remove($counterparty);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Counterparty
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function findOneByTitle(string $title): ?Counterparty
    {
        return $this->findOneBy(['title' => $title]);
    }

    public function findByFilter(CounterpartiesFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.title', 'ASC');
        if (null !== $filter->title && '' !== trim($filter->title)) {
            $qb->andWhere('LOWER(c.title) LIKE LOWER(:title)')
                ->setParameter('title', '%'.$this->escapeLike(trim($filter->title)).'%');
        }
        if (null !== $filter->pager) {
            $qb->setMaxResults($filter->pager->getLimit());
            $qb->setFirstResult($filter->pager->getOffset());
        }
        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
    }

    public function findByIds(StringCollection $ids): array
    {
        if (0 === $ids->count()) {
            return [];
        }

        return array_values($this->findBy(['id' => $ids->getList()]));
    }

    public function suggest(string $query, int $limit = 10): array
    {
        $needle = trim($query);
        if ('' === $needle) {
            return [];
        }

        /** @var list<Counterparty> $result */
        $result = $this->createQueryBuilder('c')
            ->where('LOWER(c.title) LIKE LOWER(:q)')
            ->setParameter('q', $this->escapeLike($needle).'%')
            ->orderBy('c.title', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
