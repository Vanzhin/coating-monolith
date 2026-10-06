<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Repository;

use App\Compliance\Domain\Aggregate\ProfileCompliance\RequirementDocument;
use App\Compliance\Domain\Repository\ComplianceActRepositoryInterface;
use App\Compliance\Domain\Repository\ComplianceActsFilter;
use App\Compliance\Domain\Repository\ComplianceActsSort;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Список актов выдачи (RequirementDocument) через единый {@see findByFilter}: фильтр (owner-скоуп сведён хендлером
 * в restrictProfileIds + требование/статус/период), порядок (дата выдачи, сначала свежие) и пагинация — в SQL.
 * Дата акта = подпись, иначе создание (COALESCE). ФИО для отображения обогащает хендлер (кросс-контекст Personnel).
 *
 * @extends ServiceEntityRepository<RequirementDocument>
 */
class ComplianceActRepository extends ServiceEntityRepository implements ComplianceActRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RequirementDocument::class);
    }

    public function findByFilter(ComplianceActsFilter $filter): PaginationResult
    {
        $restrict = $filter->restrictProfileIds;
        if (null !== $restrict && 0 === $restrict->count()) {
            return new PaginationResult([], 0); // сужение задано и пусто — никого
        }

        // act_date = дата подписи, иначе создания (единая «дата выдачи»). HIDDEN-алиас — чтобы по ней
        // сортировать: DQL ORDER BY не принимает COALESCE напрямую, только result variable из SELECT.
        $qb = $this->createQueryBuilder('d')
            ->addSelect('COALESCE(d.signedAt, d.createdAt) AS HIDDEN act_date')
            ->innerJoin('d.profileCompliance', 'pc')->addSelect('pc');
        $this->applySort($qb, $filter->sort);

        if (null !== $restrict) {
            $qb->andWhere('pc.profileId IN (:pids)')->setParameter('pids', $restrict->getList());
        }
        if (null !== $filter->requirementId) {
            $qb->andWhere('d.requirementId = :rid')->setParameter('rid', $filter->requirementId);
        }
        if (null !== $filter->status) {
            // status хранится строкой — привязываем backing value (enum-параметр в WHERE на varchar не конвертится).
            $qb->andWhere('d.status = :status')->setParameter('status', $filter->status->value);
        }
        if (null !== $filter->dateFrom) {
            $qb->andWhere('COALESCE(d.signedAt, d.createdAt) >= :from')->setParameter('from', $filter->dateFrom);
        }
        if (null !== $filter->dateTo) {
            $qb->andWhere('COALESCE(d.signedAt, d.createdAt) <= :to')->setParameter('to', $filter->dateTo);
        }

        if (null !== $filter->pager) {
            $qb->setFirstResult($filter->pager->getOffset())->setMaxResults($filter->pager->getLimit());
        }

        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
    }

    public function profileAndRequirementOf(string $documentId): ?array
    {
        /** @var array{profileId: string, requirementId: string}|null $row */
        $row = $this->getEntityManager()->createQuery(
            'SELECT pc.profileId AS profileId, d.requirementId AS requirementId
             FROM App\Compliance\Domain\Aggregate\ProfileCompliance\RequirementDocument d
             JOIN d.profileCompliance pc WHERE d.id = :id'
        )->setParameter('id', $documentId)->getOneOrNullResult();

        return $row;
    }

    /**
     * @param list<string> $documentIds
     *
     * @return array<string, int>
     */
    public function countPositionsByDocuments(array $documentIds): array
    {
        if ([] === $documentIds) {
            return [];
        }
        /** @var list<array{documentId: string, cnt: int}> $rows */
        $rows = $this->getEntityManager()->createQuery(
            'SELECT r.documentId AS documentId, COUNT(r.id) AS cnt
             FROM App\Compliance\Domain\Aggregate\ProfileCompliance\FulfillmentRecord r
             WHERE r.documentId IN (:ids) GROUP BY r.documentId'
        )->setParameter('ids', $documentIds)->getArrayResult();

        $byDoc = [];
        foreach ($rows as $row) {
            $byDoc[$row['documentId']] = (int) $row['cnt'];
        }

        return $byDoc;
    }

    private function applySort(QueryBuilder $qb, ComplianceActsSort $sort): void
    {
        // По колонкам документа; act_date — HIDDEN-алиас COALESCE(signedAt, createdAt). Tie-break по id — стабильно.
        match ($sort) {
            ComplianceActsSort::DEFAULT => $qb->orderBy('act_date', 'DESC'),
            ComplianceActsSort::DATE_ASC => $qb->orderBy('act_date', 'ASC'),
            ComplianceActsSort::NUMBER_ASC => $qb->orderBy('d.actNumber', 'ASC'),
            ComplianceActsSort::NUMBER_DESC => $qb->orderBy('d.actNumber', 'DESC'),
            ComplianceActsSort::STATUS_ASC => $qb->orderBy('d.status', 'ASC'),
            ComplianceActsSort::STATUS_DESC => $qb->orderBy('d.status', 'DESC'),
        };
        $qb->addOrderBy('d.id', 'ASC');
    }
}
