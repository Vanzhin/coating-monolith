<?php
declare(strict_types=1);


namespace App\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingTagsFilter;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class CoatingTagRepository extends ServiceEntityRepository implements CoatingTagRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoatingTag::class);
    }

    public function add(CoatingTag $coatingTag): void
    {
        // TODO: Implement add() method.
    }

    public function findByTitle(string $title): PaginationResult
    {
        // TODO: Implement findByTitle() method.
    }

    public function findByType(string $type): PaginationResult
    {
        // TODO: Implement findByType() method.
    }

    public function findOneById(string $id): ?CoatingTag
    {
        // TODO: Implement findOneById() method.
    }

    public function findOneByTitleAndType(string $title, ?string $type): ?CoatingTag
    {
        // TODO: Implement findOneByTitleAndType() method.
    }

    public function findByFilter(CoatingTagsFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('ct');
        $qb->orderBy('ct.title', 'ASC');
        if ($filter->title) {
            $qb->andWhere($qb->expr()->eq('LOWER(ct.title)', 'LOWER(:title)'))
                ->setParameter('title', $filter->title);
        }
        foreach ($filter->types as $key =>$type) {
            if ($type) {
                $qb->orWhere($qb->expr()->eq('ct.type', ':type'.$key))
                    ->setParameter('type'.$key, $type);
            }
            if (is_null($type)) {
                $qb->orWhere($qb->expr()->isNull('ct.type'));
            }
        }
        if ($filter->pager) {
            $qb->setMaxResults($filter->pager->getLimit());
            $qb->setFirstResult($filter->pager->getOffset());
        }
//        dd($qb->getQuery());
        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
    }
}