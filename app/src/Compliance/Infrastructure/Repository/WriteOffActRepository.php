<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Repository;

use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Compliance\Domain\Type\DocumentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WriteOffAct>
 */
class WriteOffActRepository extends ServiceEntityRepository implements WriteOffActRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WriteOffAct::class);
    }

    public function add(WriteOffAct $act): void
    {
        $this->getEntityManager()->persist($act);
        $this->getEntityManager()->flush();
    }

    public function remove(WriteOffAct $act): void
    {
        $this->getEntityManager()->remove($act);
        $this->getEntityManager()->flush();
    }

    public function findOpenDraft(string $requirementId, string $organizationId): ?WriteOffAct
    {
        // Fetch-join строк в том же запросе (readonly-id строки + to-one обратно на акт — иначе LAZY-прокси крэш).
        return $this->createQueryBuilder('a')
            ->leftJoin('a.lines', 'l')->addSelect('l')
            ->where('a.requirementId = :requirementId')
            ->andWhere('a.organizationId = :organizationId')
            ->andWhere('a.status = :status')
            ->setParameter('requirementId', $requirementId)
            ->setParameter('organizationId', $organizationId)
            ->setParameter('status', DocumentStatus::Formed->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findById(string $actId): ?WriteOffAct
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.lines', 'l')->addSelect('l')
            ->where('a.id = :id')
            ->setParameter('id', $actId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findDraftsContainingRecord(string $recordId): array
    {
        // Шаг 1: лёгкий скан на id актов — WHERE на join'нутой строке иначе обрежет fetch-join'нутую
        // коллекцию (вернёт акт только с совпавшей строкой, а не со всем составом).
        $actIds = $this->getEntityManager()->createQueryBuilder()
            ->select('a.id')
            ->from(WriteOffAct::class, 'a')
            ->innerJoin('a.lines', 'l')
            ->where('l.recordId = :recordId')
            ->andWhere('a.status = :status')
            ->setParameter('recordId', $recordId)
            ->setParameter('status', DocumentStatus::Formed->value)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $actIds) {
            return [];
        }

        // Шаг 2: полная загрузка найденных актов со ВСЕМ составом строк (fetch-join).
        /** @var list<WriteOffAct> $result */
        $result = $this->createQueryBuilder('a')
            ->leftJoin('a.lines', 'l')->addSelect('l')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $actIds)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
