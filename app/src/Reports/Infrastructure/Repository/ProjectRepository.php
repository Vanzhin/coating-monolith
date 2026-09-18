<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Repository;

use App\Reports\Domain\Aggregate\Project\Project;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Reports\Domain\Repository\ProjectsFilter;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository implements ProjectRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    public function add(Project $project): void
    {
        $this->getEntityManager()->persist($project);
        $this->getEntityManager()->flush();
    }

    public function remove(Project $project): void
    {
        $this->getEntityManager()->remove($project);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Project
    {
        // fetch-join контрагента: у Project readonly-id + to-one, ленивый прокси на нём крэшит.
        return $this->createQueryBuilder('p')
            ->addSelect('c')
            ->leftJoin('p.counterparty', 'c')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByTitle(string $title): ?Project
    {
        // Только для проверки уникальности (читает title/id, не контрагента) — join не нужен.
        return $this->findOneBy(['title' => $title]);
    }

    public function findByFilter(ProjectsFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c')
            ->leftJoin('p.counterparty', 'c')
            ->orderBy('p.title', 'ASC');
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

    public function findByIds(StringCollection $ids): array
    {
        if (0 === $ids->count()) {
            return [];
        }

        /** @var list<Project> $result */
        $result = $this->createQueryBuilder('p')
            ->addSelect('c')
            ->leftJoin('p.counterparty', 'c')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids->getList())
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function suggest(string $query, int $limit = 10): array
    {
        $needle = trim($query);
        if ('' === $needle) {
            return [];
        }

        /** @var list<Project> $result */
        $result = $this->createQueryBuilder('p')
            ->addSelect('c')
            ->leftJoin('p.counterparty', 'c')
            ->where('LOWER(p.title) LIKE LOWER(:q)')
            ->setParameter('q', $this->escapeLike($needle).'%')
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
