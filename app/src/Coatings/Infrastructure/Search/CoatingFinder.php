<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Search;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingSearch;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\PaginationResult;
use App\Shared\Domain\Repository\RangeFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Сервис read-side для поиска покрытий.
 * Принимает CoatingsFilter целиком: FTS-условие и фасеты строятся в одном QueryBuilder.
 */
final class CoatingFinder
{
    private const FTS_LANG = 'russian';
    private const FUZZY_SIMILARITY_THRESHOLD = 0.4;
    private const FUZZY_LIMIT = 10;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function fullText(CoatingsFilter $filter): PaginationResult
    {
        $qb = $this->coatingQueryBuilder();
        $this->applyFtsClause($qb, $filter);
        $this->applyFacets($qb, $filter);
        $this->applyPaging($qb, $filter->pager);

        return $this->paginate($qb);
    }

    public function fuzzyTitle(CoatingsFilter $filter): PaginationResult
    {
        if ($filter->search === null) {
            return new PaginationResult([], 0);
        }

        $similarity = 'GREATEST(WORD_SIMILARITY(:search, cc.title), WORD_SIMILARITY(:search, cc.description))';

        $qb = $this->coatingQueryBuilder();
        $qb->andWhere($similarity . ' > :threshold')
            ->addSelect($similarity . ' AS HIDDEN sim')
            ->orderBy('sim', 'DESC')
            ->setMaxResults(self::FUZZY_LIMIT)
            ->setParameter('search', $filter->search)
            ->setParameter('threshold', self::FUZZY_SIMILARITY_THRESHOLD);

        $this->applyFacets($qb, $filter);

        return $this->paginate($qb);
    }

    private function applyFtsClause(QueryBuilder $qb, CoatingsFilter $filter): void
    {
        if ($filter->search === null) {
            $qb->orderBy('cc.title', 'ASC');
            return;
        }

        $tsquery = $this->buildPrefixTsQuery($filter->search);
        if ($tsquery === '') {
            $qb->andWhere('1 = 0');
            return;
        }

        $qb->innerJoin(CoatingSearch::class, 's', 'WITH', 's.coatingId = cc.id')
            ->andWhere('TS_MATCH(s.searchVector, TO_TSQUERY(:lang, :tsquery)) = TRUE')
            ->addSelect('TS_RANK_CD(s.searchVector, TO_TSQUERY(:lang, :tsquery)) AS HIDDEN fts_rank')
            ->orderBy('fts_rank', 'DESC')
            ->setParameter('lang', self::FTS_LANG)
            ->setParameter('tsquery', $tsquery);
    }

    private function applyFacets(QueryBuilder $qb, CoatingsFilter $filter): void
    {
        $this->applyManufacturerFacet($qb, $filter);
        $this->applyRangeFacet($qb, 'applicationMinTemp', 'appMinTemp', $filter->applicationMinTemp);
        $this->applyRangeFacet($qb, 'volumeSolid', 'volSolid', $filter->volumeSolid);
    }

    private function applyManufacturerFacet(QueryBuilder $qb, CoatingsFilter $filter): void
    {
        if ($filter->manufacturerIds->count() === 0) {
            return;
        }
        $qb->andWhere('cc.manufacturer IN (:manufacturerIds)')
            ->setParameter('manufacturerIds', $filter->manufacturerIds->getList());
    }

    /**
     * Числовой range-фасет "От..До" (обе границы включительно, обе опциональные).
     * $entityField — имя поля в Doctrine-сущности; $paramPrefix — префикс имени
     * параметра, чтобы не коллидировать с другими range-фасетами в одном запросе.
     */
    private function applyRangeFacet(QueryBuilder $qb, string $entityField, string $paramPrefix, ?RangeFilter $range): void
    {
        if ($range === null) {
            return;
        }
        if ($range->from !== null) {
            $qb->andWhere("cc.$entityField >= :{$paramPrefix}From")
                ->setParameter("{$paramPrefix}From", $range->from);
        }
        if ($range->to !== null) {
            $qb->andWhere("cc.$entityField <= :{$paramPrefix}To")
                ->setParameter("{$paramPrefix}To", $range->to);
        }
    }

    /**
     * Превращает пользовательский ввод в безопасный tsquery с префиксным сопоставлением.
     * «быстросох эпоксидн» -> «быстросох:* & эпоксидн:*».
     */
    private function buildPrefixTsQuery(string $query): string
    {
        $sanitized = preg_replace('/[&|!()<>:\'"\\\\*]/u', ' ', $query) ?? '';
        $words = preg_split('/[\s\-.,;]+/u', trim($sanitized), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return '';
        }

        return implode(' & ', array_map(static fn(string $word) => $word . ':*', $words));
    }

    private function coatingQueryBuilder(): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('cc', 't')
            ->from(Coating::class, 'cc')
            ->leftJoin('cc.tags', 't');
    }

    private function applyPaging(QueryBuilder $qb, ?Pager $pager): void
    {
        if ($pager === null) {
            return;
        }
        $qb->setMaxResults($pager->getLimit());
        $qb->setFirstResult($pager->getOffset());
    }

    /**
     * fetchJoinCollection=true: Paginator делает 2-фазный план
     * (DISTINCT id-subquery + data-query с join'ами), это правильно
     * считает LIMIT/OFFSET при leftJoin на to-many tags.
     */
    private function paginate(QueryBuilder $qb): PaginationResult
    {
        $paginator = new Paginator($qb->getQuery(), true);

        return new PaginationResult(
            iterator_to_array($paginator->getIterator()),
            $paginator->count(),
        );
    }
}
