<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Proposals\Domain\Repository\GeneralProposalInfoRepositoryInterface;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function findByFilter(CoatingsFilter $filter): PaginationResult
    {
        // TODO: Implement findByFilter() method.
    }
}