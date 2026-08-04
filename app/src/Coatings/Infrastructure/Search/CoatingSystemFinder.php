<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Search;

use App\Coatings\Domain\Aggregate\CoatingSystem\Iso12944\IsoCorrosivityCategory;
use App\Coatings\Domain\Aggregate\CoatingSystem\Iso12944\IsoDurability;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Shared\Domain\Repository\RangeFilter;
use App\Shared\Domain\Repository\SearchResult;
use App\Shared\Infrastructure\Database\FullTextSearch\PrefixTsQueryBuilder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Поисковый Finder для систем покрытий. JOIN трёх кэш-таблиц:
 *   coating_system (cs)         — основные атрибуты (title, substrate)
 *   coating_system_search (css) — FTS tsvector + min/max числовые поля
 *   coating_system_compliance   — фасет по стандартам через EXISTS
 *   coating_system_tag          — фасет по тегам через EXISTS
 *
 * Возвращает id-строки + total; hydration агрегатов — на вызывающей стороне через findByIds.
 */
final class CoatingSystemFinder
{
    private const FTS_LANG = 'russian';

    public function __construct(
        private readonly Connection $conn,
        private readonly PrefixTsQueryBuilder $tsQueryBuilder,
    ) {
    }

    public function find(CoatingSystemsFilter $filter): SearchResult
    {
        $qb = $this->conn->createQueryBuilder();
        // INNER JOIN: каждая система синхронно получает строку coating_system_search
        // при мутации (RefreshCacheOnCoatingSystemMutatedHandler, sync-шина). Система без
        // строки в индексе не должна показываться в поиске вообще — иначе она мелькала бы
        // при пустом фильтре и исчезала при любом q/range. INNER держит поведение единым.
        $qb->select('cs.id')
            ->from('coating_system', 'cs')
            ->innerJoin('cs', 'coating_system_search', 'css', 'css.system_id = cs.id');

        $this->applyFts($qb, $filter);
        $this->applySubstrates($qb, $filter);
        $this->applyCompliance($qb, $filter);
        $this->applyTags($qb, $filter);
        $this->applyCoatings($qb, $filter);
        $this->applyRanges($qb, $filter);

        $countQb = clone $qb;
        $countQb->select('COUNT(DISTINCT cs.id) AS cnt');
        $total = (int) $countQb->executeQuery()->fetchOne();

        $this->applySort($qb, $filter);
        $qb->setFirstResult($filter->pager->getOffset())
            ->setMaxResults($filter->pager->getLimit());

        $ids = array_values(array_map('strval', $qb->executeQuery()->fetchFirstColumn()));

        return new SearchResult($ids, $total);
    }

    private function applyFts(QueryBuilder $qb, CoatingSystemsFilter $filter): void
    {
        if (null === $filter->search) {
            return;
        }

        $tsquery = $this->tsQueryBuilder->build($filter->search->value, PrefixTsQueryBuilder::CONJUNCTION_AND);
        if ('' === $tsquery) {
            $qb->andWhere('1 = 0');

            return;
        }

        $qb->andWhere('css.search_tsvector @@ TO_TSQUERY(:fts_lang, :fts_tsquery)')
            ->setParameter('fts_lang', self::FTS_LANG)
            ->setParameter('fts_tsquery', $tsquery);
    }

    private function applySubstrates(QueryBuilder $qb, CoatingSystemsFilter $filter): void
    {
        if ([] === $filter->substrates) {
            return;
        }

        $values = array_map(static fn ($s) => $s->value, $filter->substrates);
        $qb->andWhere('cs.substrate IN (:substrates)')
            ->setParameter('substrates', $values, ArrayParameterType::STRING);
    }

    private function applyCompliance(QueryBuilder $qb, CoatingSystemsFilter $filter): void
    {
        if (null === $filter->standard) {
            return;
        }

        $sub = 'SELECT 1 FROM coating_system_compliance csc WHERE csc.system_id = cs.id AND csc.standard = :csc_standard';
        $qb->setParameter('csc_standard', $filter->standard->value);

        // Кеш содержит только сильнейшие пары (см. ComplianceMatches::strongestOnly);
        // фильтр «C3-HIGH» должен находить и системы с максимумом C5-VERY_HIGH, поэтому
        // ищем по всем категориям той же семьи ≥ выбранной и всем долговечностям ≥ выбранной.
        if (null !== $filter->category) {
            $category = IsoCorrosivityCategory::tryFrom($filter->category);
            if (null === $category) {
                $qb->andWhere('1 = 0');

                return;
            }
            $sub .= ' AND csc.category IN (:csc_category_list)';
            $qb->setParameter('csc_category_list', $category->atOrAboveInFamily(), ArrayParameterType::STRING);
        }

        if (null !== $filter->durability) {
            $durability = IsoDurability::tryFrom($filter->durability);
            if (null === $durability) {
                $qb->andWhere('1 = 0');

                return;
            }
            $sub .= ' AND csc.durability IN (:csc_durability_list)';
            $qb->setParameter('csc_durability_list', $durability->atOrAbove(), ArrayParameterType::STRING);
        }

        $qb->andWhere("EXISTS ($sub)");
    }

    private function applyTags(QueryBuilder $qb, CoatingSystemsFilter $filter): void
    {
        if (0 === $filter->tagIds->count()) {
            return;
        }

        $qb->andWhere('EXISTS (SELECT 1 FROM coating_system_tag cst WHERE cst.coating_system_id = cs.id AND cst.tag_id IN (:tag_ids))')
            ->setParameter('tag_ids', $filter->tagIds->getList(), ArrayParameterType::STRING);
    }

    private function applyCoatings(QueryBuilder $qb, CoatingSystemsFilter $filter): void
    {
        if (0 === $filter->coatingIds->count()) {
            return;
        }

        // OR: система проходит, если у неё есть слой с любым из выбранных покрытий.
        // EXISTS (не JOIN) — чтобы не размножать строки cs и не ломать COUNT/пагинацию.
        $qb->andWhere('EXISTS (SELECT 1 FROM coating_system_layer csl WHERE csl.system_id = cs.id AND csl.coating_id IN (:coating_ids))')
            ->setParameter('coating_ids', $filter->coatingIds->getList(), ArrayParameterType::STRING);
    }

    private function applyRanges(QueryBuilder $qb, CoatingSystemsFilter $filter): void
    {
        $this->applyRangeFacet($qb, 'css.max_layer_application_min_temp', 'app_temp', $filter->applicationMinTemp);
        $this->applyRangeFacet($qb, 'css.min_application_time_at_20_minutes', 'app_time', $filter->minApplicationTimeAt20);
    }

    private function applyRangeFacet(QueryBuilder $qb, string $column, string $prefix, ?RangeFilter $range): void
    {
        if (null === $range) {
            return;
        }

        if (null !== $range->from) {
            $qb->andWhere("$column >= :{$prefix}_from")
                ->setParameter("{$prefix}_from", $range->from);
        }

        if (null !== $range->to) {
            $qb->andWhere("$column <= :{$prefix}_to")
                ->setParameter("{$prefix}_to", $range->to);
        }
    }

    private function applySort(QueryBuilder $qb, CoatingSystemsFilter $filter): void
    {
        match ($filter->sort) {
            CoatingSystemSort::DEFAULT => null !== $filter->search
                ? $qb->addSelect('TS_RANK_CD(css.search_tsvector, TO_TSQUERY(:fts_lang, :fts_tsquery)) AS fts_rank')
                      ->orderBy('fts_rank', 'DESC')
                      ->addOrderBy('cs.title', 'ASC')
                : $qb->orderBy('cs.title', 'ASC'),
            CoatingSystemSort::TITLE_ASC => $qb->orderBy('cs.title', 'ASC'),
            CoatingSystemSort::TITLE_DESC => $qb->orderBy('cs.title', 'DESC'),
            CoatingSystemSort::MIN_APPLICATION_TIME_ASC => $qb->orderBy('css.min_application_time_at_20_minutes', 'ASC NULLS LAST'),
            CoatingSystemSort::MIN_APPLICATION_TIME_DESC => $qb->orderBy('css.min_application_time_at_20_minutes', 'DESC NULLS LAST'),
        };
    }
}
