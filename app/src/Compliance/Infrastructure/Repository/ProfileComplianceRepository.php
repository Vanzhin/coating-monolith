<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Repository;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
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
            ->leftJoin('p.documents', 'd')->addSelect('d')
            ->where('p.profileId = :pid')->setParameter('pid', $profileId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForDashboard(?StringCollection $restrictProfileIds, StringCollection $departmentIds): array
    {
        if (null !== $restrictProfileIds && 0 === $restrictProfileIds->count()) {
            return []; // сужение задано и пусто — никого
        }

        // innerJoin obligations = только учёты с обязанностями (+ fetch-join их); документы — leftJoin.
        $qb = $this->createQueryBuilder('p')
            ->innerJoin('p.obligations', 'o')->addSelect('o')
            ->leftJoin('p.documents', 'd')->addSelect('d');

        if (null !== $restrictProfileIds) {
            $qb->andWhere('p.profileId IN (:pids)')->setParameter('pids', $restrictProfileIds->getList());
        }
        if ($departmentIds->count() > 0) {
            // departmentId денормализован в obligation (один на человека) — фильтр отдела здесь.
            $qb->andWhere('o.departmentId IN (:depts)')->setParameter('depts', $departmentIds->getList());
        }

        /** @var list<ProfileCompliance> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function findAllProfileIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()
            ->fetchFirstColumn('SELECT profile_id FROM compliance_profile_compliance');

        return $ids;
    }
}
