<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Repository;

use App\Personnel\Domain\Aggregate\Position\Position;
use App\Personnel\Domain\Repository\PositionRepositoryInterface;
use App\Personnel\Domain\Repository\PositionsFilter;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Position>
 */
class PositionRepository extends ServiceEntityRepository implements PositionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Position::class);
    }

    public function add(Position $position): void
    {
        $this->getEntityManager()->persist($position);
        $this->getEntityManager()->flush();
    }

    public function remove(Position $position): void
    {
        $this->getEntityManager()->remove($position);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Position
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function findOneByTitle(string $title): ?Position
    {
        return $this->findOneBy(['title' => $title]);
    }

    public function findByIds(StringCollection $ids): array
    {
        if (0 === $ids->count()) {
            return [];
        }

        return array_values($this->findBy(['id' => $ids->getList()]));
    }

    public function findByFilter(PositionsFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('p')->orderBy('p.title', 'ASC');
        if (null !== $filter->title && '' !== trim($filter->title)) {
            $qb->andWhere('LOWER(p.title) LIKE LOWER(:title)')
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

        /** @var list<Position> $result */
        $result = $this->createQueryBuilder('p')
            ->where('LOWER(p.title) LIKE LOWER(:q)')
            ->setParameter('q', '%'.$this->escapeLike($needle).'%')
            ->orderBy('p.title', 'ASC')
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
