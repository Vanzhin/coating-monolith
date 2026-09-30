<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Repository;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProfileCompliance>
 */
class ProfileComplianceRepository extends ServiceEntityRepository implements ProfileComplianceRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfileCompliance::class);
    }

    public function add(ProfileCompliance $profileCompliance): void
    {
        $this->getEntityManager()->persist($profileCompliance);
        $this->getEntityManager()->flush();
    }

    public function findByProfile(string $profileId): ?ProfileCompliance
    {
        // Fetch-join детей в одном запросе (readonly-id детей + to-one на корень — иначе прокси-краш).
        return $this->createQueryBuilder('p')
            ->leftJoin('p.obligations', 'o')->addSelect('o')
            ->leftJoin('p.records', 'r')->addSelect('r')
            ->where('p.profileId = :pid')->setParameter('pid', $profileId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAllProfileIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()
            ->fetchFirstColumn('SELECT profile_id FROM compliance_profile_compliance');

        return $ids;
    }
}
