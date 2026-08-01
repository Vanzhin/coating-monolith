<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Search;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Aggregate\Coating\CoatingTagSearch;
use App\Shared\Infrastructure\Database\FullTextSearch\PrefixTsQueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Search-сервис для CoatingTag: prefix-FTS по title + fuzzy-fallback (pg_trgm).
 * Используется suggest-эндпоинтом для Tagify-autocomplete.
 */
final class CoatingTagFinder
{
    private const FTS_LANG = 'russian';
    private const FUZZY_SIMILARITY_THRESHOLD = 0.4;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PrefixTsQueryBuilder $tsQueryBuilder,
    ) {
    }

    /**
     * @return list<CoatingTag>
     */
    public function suggest(string $query, ?string $type, int $limit = 10): array
    {
        $query = trim($query);
        if ('' === $query) {
            return [];
        }

        $ftsResults = $this->fullText($query, $type, $limit);
        if ([] !== $ftsResults) {
            return $ftsResults;
        }

        return $this->fuzzyTitle($query, $type, $limit);
    }

    /**
     * @return list<CoatingTag>
     */
    private function fullText(string $query, ?string $type, int $limit): array
    {
        $tsquery = $this->tsQueryBuilder->build($query, PrefixTsQueryBuilder::CONJUNCTION_AND);
        if ('' === $tsquery) {
            return [];
        }

        $qb = $this->coatingTagQueryBuilder();
        $qb->innerJoin(CoatingTagSearch::class, 's', 'WITH', 's.tagId = t.id')
            ->andWhere('TS_MATCH(s.searchVector, TO_TSQUERY(:lang, :tsquery)) = TRUE')
            ->addSelect('TS_RANK_CD(s.searchVector, TO_TSQUERY(:lang, :tsquery)) AS HIDDEN fts_rank')
            ->orderBy('fts_rank', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('lang', self::FTS_LANG)
            ->setParameter('tsquery', $tsquery);

        $this->applyTypeFilter($qb, $type);

        return array_values($qb->getQuery()->getResult());
    }

    /**
     * @return list<CoatingTag>
     */
    private function fuzzyTitle(string $query, ?string $type, int $limit): array
    {
        $similarity = 'WORD_SIMILARITY(:search, t.title)';

        $qb = $this->coatingTagQueryBuilder();
        $qb->andWhere($similarity.' > :threshold')
            ->addSelect($similarity.' AS HIDDEN sim')
            ->orderBy('sim', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('search', $query)
            ->setParameter('threshold', self::FUZZY_SIMILARITY_THRESHOLD);

        $this->applyTypeFilter($qb, $type);

        return array_values($qb->getQuery()->getResult());
    }

    private function applyTypeFilter(QueryBuilder $qb, ?string $type): void
    {
        if (null === $type) {
            return;
        }
        $qb->andWhere('t.type = :type')->setParameter('type', $type);
    }

    private function coatingTagQueryBuilder(): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('t')
            ->from(CoatingTag::class, 't');
    }
}
