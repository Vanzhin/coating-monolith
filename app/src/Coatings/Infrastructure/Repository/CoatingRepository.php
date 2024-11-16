<?php
declare(strict_types=1);


namespace App\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class CoatingRepository extends ServiceEntityRepository implements CoatingRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Coating::class);
    }


    public function add(Coating $coating): void
    {
        $this->getEntityManager()->persist($coating);
        $this->getEntityManager()->flush();
    }

    public function remove(Coating $coating): void
    {
        $this->getEntityManager()->remove($coating);
        $this->getEntityManager()->flush();
    }

    public function findByFilter(CoatingsFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('cc');
        $qb->orderBy('cc.title', 'ASC');
        if ($filter->title) {
            $qb->where($qb->expr()->like('LOWER(cc.title)', 'LOWER(:title)'))
                ->setParameter('title', '%' . $filter->title . '%');
        }
        if ($filter->pager) {
            $qb->setMaxResults($filter->pager->getLimit());
            $qb->setFirstResult($filter->pager->getOffset());
        }
        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
    }

    public function findOneById(string $id): ?Coating
    {
        return $this->findOneBy(['id' => $id]);
    }
}