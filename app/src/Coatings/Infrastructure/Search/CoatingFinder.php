<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Search;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingSearch;
use App\Coatings\Domain\Repository\CoatingSort;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Coatings\Domain\Repository\ThermalEnvironment;
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
        $this->applyUserSort($qb, $filter->sort);
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
            ->setParameter('search', $filter->search->value)
            ->setParameter('threshold', self::FUZZY_SIMILARITY_THRESHOLD);

        $this->applyFacets($qb, $filter);
        $this->applyUserSort($qb, $filter->sort);

        return $this->paginate($qb);
    }

    /**
     * Пользовательская сортировка. DEFAULT — не трогаем ORDER BY, оставляем
     * тот, что уже поставил applyFtsClause / fuzzyTitle (rank или title).
     * Иные значения — перекрывают дефолт целиком.
     */
    private function applyUserSort(QueryBuilder $qb, CoatingSort $sort): void
    {
        if ($sort === CoatingSort::DEFAULT) {
            return;
        }

        match ($sort) {
            CoatingSort::TITLE_ASC        => $qb->resetDQLPart('orderBy')->orderBy('cc.title', 'ASC'),
            CoatingSort::TITLE_DESC       => $qb->resetDQLPart('orderBy')->orderBy('cc.title', 'DESC'),
            CoatingSort::MANUFACTURER_ASC => $qb->resetDQLPart('orderBy')
                ->leftJoin('cc.manufacturer', 'sortMf')
                ->orderBy('sortMf.title', 'ASC')
                ->addOrderBy('cc.title', 'ASC'),
            default                       => null,
        };
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
        $this->applyTagFacet($qb, $filter);
        $this->applyThermalExposureFacet($qb, $filter);
        $this->applyBaseFacet($qb, $filter);
    }

    /**
     * Фасет «тип связующего». Multi-value OR: покрытие подходит, если его base
     * в переданном списке. Значения — ISO-коды CoatingBase (валидируются в
     * контроллере, сюда попадают только валидные).
     */
    private function applyBaseFacet(QueryBuilder $qb, CoatingsFilter $filter): void
    {
        if ($filter->baseValues->count() === 0) {
            return;
        }
        $qb->andWhere('cc.base IN (:baseValues)')
            ->setParameter('baseValues', $filter->baseValues->getList());
    }

    /**
     * Фасет «покрытие держит T °C в среде E». Семантика зеркалит
     * ThermalExposureLimits::covers: NULL-граница = «не задокументировано» =
     * без ограничения в эту сторону (иначе покрытия с одной документированной
     * границей были бы бесполезны в поиске).
     *
     * Покрытия без ThermalExposureLimits в нужной среде вообще (колонка NULL)
     * НЕ попадают в выборку — данных нет, о материале ничего не известно.
     */
    private function applyThermalExposureFacet(QueryBuilder $qb, CoatingsFilter $filter): void
    {
        if (!$filter->hasThermalFacet()) {
            return;
        }

        $entityField = match ($filter->thermalEnvironment) {
            ThermalEnvironment::DRY_HEAT  => 'cc.dryHeatExposure',
            ThermalEnvironment::IMMERSION => 'cc.immersionExposure',
        };

        $qb->andWhere("$entityField IS NOT NULL")
            ->andWhere("(JSONB_GET_INT($entityField, 'continuous_min') IS NULL OR JSONB_GET_INT($entityField, 'continuous_min') <= :thermTemp)");

        if ($filter->thermalIncludingPeak) {
            // Верхняя эффективная граница: peak_max ?? continuous_max. Если оба NULL —
            // ограничения сверху нет вообще.
            $qb->andWhere(
                "(COALESCE(JSONB_GET_INT($entityField, 'peak_max'), JSONB_GET_INT($entityField, 'continuous_max')) IS NULL " .
                "OR COALESCE(JSONB_GET_INT($entityField, 'peak_max'), JSONB_GET_INT($entityField, 'continuous_max')) >= :thermTemp)"
            );
        } else {
            $qb->andWhere("(JSONB_GET_INT($entityField, 'continuous_max') IS NULL OR JSONB_GET_INT($entityField, 'continuous_max') >= :thermTemp)");
        }

        $qb->setParameter('thermTemp', $filter->thermalTemperature);
    }

    /**
     * AND-семантика: покрытие обязано иметь ВСЕ выбранные теги. Реализуется
     * через отдельный EXISTS-подзапрос на каждый tag id — GROUP BY подход
     * конфликтует с уже существующим addSelect('t') из fetchJoinCollection
     * (Postgres требует не-агрегируемые колонки в GROUP BY).
     */
    private function applyTagFacet(QueryBuilder $qb, CoatingsFilter $filter): void
    {
        if ($filter->tagIds->count() === 0) {
            return;
        }
        foreach ($filter->tagIds->getList() as $i => $tagId) {
            $paramName = "filterTagId{$i}";
            $qb->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s fc%d INNER JOIN fc%d.tags ft%d WHERE fc%d = cc AND ft%d.id = :%s)',
                Coating::class,
                $i, $i, $i, $i, $i,
                $paramName,
            ))->setParameter($paramName, $tagId);
        }
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
     *
     * Слова берём из SearchQuery::words() — единственный источник разбиения,
     * тот же что и в CoatingRepository::hasSingleWord через SearchQuery.
     */
    private function buildPrefixTsQuery(SearchQuery $search): string
    {
        // Санитайзим tsquery-мета до разбиения на слова, чтобы не пропустить
        // «cc:special» → как один токен с двоеточием.
        $sanitized = preg_replace('/[&|!()<>:\'"\\\\*]/u', ' ', $search->value) ?? '';
        // Разбиваем тем же splitter'ом, но уже из очищенной строки — иначе
        // нельзя, потому что SearchQuery::words() читает исходный value.
        $words = preg_split('/[\s\-.,;]+/u', $sanitized, -1, PREG_SPLIT_NO_EMPTY);
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
