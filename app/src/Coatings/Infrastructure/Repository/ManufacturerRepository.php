<?php
declare(strict_types=1);


namespace App\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Coatings\Domain\Repository\ManufacturersFilter;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class ManufacturerRepository extends ServiceEntityRepository implements ManufacturerRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Manufacturer::class);
    }

    public function add(Manufacturer $manufacturer): void
    {
        $this->getEntityManager()->persist($manufacturer);
        $this->getEntityManager()->flush();
    }

    public function findOneByTitle(string $title): ?Manufacturer
    {
        return $this->findOneBy(['title' => $title]);
    }

    public function findOneById(string $manufacturerId): ?Manufacturer
    {
        return $this->findOneBy(['id' => $manufacturerId]);
    }

    public function findByFilter(ManufacturersFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('cm');
        //todo запихать сортинг в фильтр
        $qb->orderBy('cm.title', 'ASC');
        if ($filter->title) {
            $qb->where($qb->expr()->like('LOWER(cm.title)', 'LOWER(:title)'))
                ->setParameter('title', '%' . $filter->title . '%');
        }
        if ($filter->pager) {
            $qb->setMaxResults($filter->pager->getLimit());
            $qb->setFirstResult($filter->pager->getOffset());
        }
        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
    }
}