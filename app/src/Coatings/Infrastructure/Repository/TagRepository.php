<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
use App\Coatings\Domain\Repository\TagsFilter;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class TagRepository extends ServiceEntityRepository implements TagRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    public function add(Tag $coatingTag): void
    {
        $this->getEntityManager()->persist($coatingTag);
        $this->getEntityManager()->flush();
    }

    public function findByTitle(string $title): PaginationResult
    {
        throw new \LogicException('findByTitle() not implemented');
    }

    public function findByType(string $type): PaginationResult
    {
        throw new \LogicException('findByType() not implemented');
    }

    public function findOneById(string $id): ?Tag
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function findByIds(StringCollection $ids): array
    {
        if (0 === $ids->count()) {
            return [];
        }

        return $this->findBy(['id' => $ids->getList()]);
    }

    public function findOneByTitleAndType(string $title, ?string $type): ?Tag
    {
        return $this->findOneBy(['title' => $title, 'type' => $type]);
    }

    public function findByFilter(TagsFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('ct');
        $qb->orderBy('ct.title', 'ASC');
        if ($filter->title) {
            $qb->andWhere($qb->expr()->eq('LOWER(ct.title)', 'LOWER(:title)'))
                ->setParameter('title', $filter->title);
        }
        foreach ($filter->types as $key => $type) {
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
