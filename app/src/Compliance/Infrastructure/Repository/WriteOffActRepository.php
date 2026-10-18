<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Repository;

use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;
use App\Compliance\Domain\Repository\WriteOffActFilter;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Compliance\Domain\Type\DocumentStatus;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    public function findSignedForProfileRequirement(string $profileId, string $requirementId): array
    {
        // Шаг 1: id подписанных актов требования, у которых есть строка этого профиля (WHERE на join'нутой
        // строке иначе обрежет fetch-join'нутую коллекцию до совпавших строк — а дальше нужен весь состав).
        $actIds = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT a.id')
            ->from(WriteOffAct::class, 'a')
            ->innerJoin('a.lines', 'l')
            ->where('a.requirementId = :requirementId')
            ->andWhere('a.status = :status')
            ->andWhere('l.profileId = :profileId')
            ->setParameter('requirementId', $requirementId)
            ->setParameter('status', DocumentStatus::Signed->value)
            ->setParameter('profileId', $profileId)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $actIds) {
            return [];
        }

        // Шаг 2: полная загрузка со ВСЕМ составом строк (строки чужих профилей того же акта отфильтрует вызывающий).
        /** @var list<WriteOffAct> $result */
        $result = $this->createQueryBuilder('a')
            ->leftJoin('a.lines', 'l')->addSelect('l')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $actIds)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Список актов с фильтром + пагинацией (кросс-организационный, для админа). Тот же двухшаговый приём,
     * что у {@see findDraftsContainingRecord}/{@see findSignedForProfileRequirement}: сперва лёгкий скан id
     * страницы с LIMIT/OFFSET (fetch-join строк сломал бы постраничность — JOIN размножает строки акта по
     * количеству его позиций), затем полная загрузка найденных актов со всем составом строк одним запросом,
     * с восстановлением порядка страницы (`IN` не гарантирует порядок).
     */
    public function findByFilter(WriteOffActFilter $filter): PaginationResult
    {
        $pager = $filter->pager;
        $filtered = $this->filteredQueryBuilder($filter);

        $total = (int) (clone $filtered)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<string> $actIds */
        $actIds = (clone $filtered)
            ->select('a.id')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult($pager->getOffset())
            ->setMaxResults($pager->getLimit())
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $actIds) {
            return new PaginationResult([], $total);
        }

        /** @var list<WriteOffAct> $loaded */
        $loaded = $this->createQueryBuilder('a')
            ->leftJoin('a.lines', 'l')->addSelect('l')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $actIds)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($loaded as $act) {
            $byId[$act->getId()] = $act;
        }
        $ordered = [];
        foreach ($actIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return new PaginationResult($ordered, $total);
    }

    private function filteredQueryBuilder(WriteOffActFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a');
        if (null !== $filter->organizationId) {
            $qb->andWhere('a.organizationId = :organizationId')->setParameter('organizationId', $filter->organizationId);
        }
        if (null !== $filter->requirementId) {
            $qb->andWhere('a.requirementId = :requirementId')->setParameter('requirementId', $filter->requirementId);
        }
        if (null !== $filter->status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $filter->status->value);
        }
        if (null !== $filter->actNumber) {
            $qb->andWhere('a.actNumber = :actNumber')->setParameter('actNumber', $filter->actNumber);
        }

        return $qb;
    }
}
