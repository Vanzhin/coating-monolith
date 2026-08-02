<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatingSystemsForSuggest;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Infrastructure\Database\FullTextSearch\PrefixTsQueryBuilder;
use Doctrine\DBAL\Connection;

final readonly class SearchCoatingSystemsForSuggestQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private Connection $conn,
        private PrefixTsQueryBuilder $tsQueryBuilder,
    ) {
    }

    public function __invoke(SearchCoatingSystemsForSuggestQuery $q): SearchCoatingSystemsForSuggestQueryResult
    {
        $trimmed = trim($q->q);
        if ('' === $trimmed) {
            return new SearchCoatingSystemsForSuggestQueryResult([]);
        }

        $tsquery = $this->tsQueryBuilder->build($trimmed, PrefixTsQueryBuilder::CONJUNCTION_AND);
        $ftsCondition = '' !== $tsquery
            ? 'css.search_tsvector @@ TO_TSQUERY(:lang, :tsquery)'
            : '1 = 0';

        $params = [
            'like' => '%'.$trimmed.'%',
            'limit' => $q->limit,
        ];
        $types = ['limit' => \PDO::PARAM_INT];
        if ('' !== $tsquery) {
            $params['lang'] = 'russian';
            $params['tsquery'] = $tsquery;
        }

        $rows = $this->conn->fetchAllAssociative(
            <<<SQL
                SELECT cs.id, cs.title
                FROM coating_system cs
                LEFT JOIN coating_system_search css ON css.system_id = cs.id
                WHERE $ftsCondition
                   OR cs.title ILIKE :like
                ORDER BY cs.title
                LIMIT :limit
                SQL,
            $params,
            $types,
        );

        $items = array_map(
            static fn (array $r) => ['id' => (string) $r['id'], 'title' => (string) $r['title']],
            $rows,
        );

        return new SearchCoatingSystemsForSuggestQueryResult($items);
    }
}
