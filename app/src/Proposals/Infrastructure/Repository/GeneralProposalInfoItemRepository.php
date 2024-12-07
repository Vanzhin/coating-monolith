<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfoItem;
use App\Proposals\Domain\Repository\GeneralProposalInfoItemRepositoryInterface;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class GeneralProposalInfoItemRepository extends ServiceEntityRepository implements GeneralProposalInfoItemRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GeneralProposalInfoItem::class);
    }


    public function add(Coating $coating): void
    {
        // TODO: Implement add() method.
    }

    public function findOneByProposalIdAndCoatNumber(string $proposalId, int $coatNumber): ?GeneralProposalInfoItem
    {
        return $this->findOneBy(['proposal' => $proposalId, 'coatNumber' => $coatNumber]);
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