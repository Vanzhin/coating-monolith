<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Proposals\Domain\Repository\GeneralProposalInfoFilter;
use App\Proposals\Domain\Repository\GeneralProposalInfoRepositoryInterface;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class GeneralProposalInfoRepository extends ServiceEntityRepository implements GeneralProposalInfoRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GeneralProposalInfo::class);
    }


    public function add(GeneralProposalInfo $generalProposalInfo): void
    {
        $this->getEntityManager()->persist($generalProposalInfo);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Coating
    {
        // TODO: Implement findOneById() method.
    }

    public function findOneByNumber(string $number): ?GeneralProposalInfo
    {
        // TODO: Implement findOneByNumber() method.
    }

    public function remove(Coating $coating): void
    {
        // TODO: Implement remove() method.
    }

    public function findByFilter(GeneralProposalInfoFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('gp');
        $qb->andWhere($qb->expr()->eq('gp.ownerId', ':owner_id'))
            ->setParameter('owner_id', $filter->userId);
        $qb->addOrderBy('gp.updatedAt', 'ASC');
        $qb->addOrderBy('gp.createdAt', 'ASC');
        if ($filter->search) {
            $qb->andWhere($qb->expr()->like('LOWER(gp.description)', 'LOWER(:search)'))
                ->setParameter('search', '%' . $filter->search . '%');
        }
        if ($filter->pager) {
            $qb->setMaxResults($filter->pager->getLimit());
            $qb->setFirstResult($filter->pager->getOffset());
        }
        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
    }
}