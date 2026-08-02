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

        $rows = $this->conn->fetchAllAssociative(
            <<<'SQL'
                SELECT cs.id, cs.title
                FROM coating_system cs
                LEFT JOIN coating_system_search css ON css.system_id = cs.id
                WHERE css.search_tsvector @@ TO_TSQUERY(:lang, :tsquery)
                   OR cs.title ILIKE :like
                ORDER BY cs.title
                LIMIT :limit
                SQL,
            [
                'lang' => 'russian',
                'tsquery' => $tsquery,
                'like' => '%'.$trimmed.'%',
                'limit' => $q->limit,
            ],
            ['limit' => \PDO::PARAM_INT],
        );

        $items = array_map(
            static fn (array $r) => ['id' => (string) $r['id'], 'title' => (string) $r['title']],
            $rows,
        );

        return new SearchCoatingSystemsForSuggestQueryResult($items);
    }
}
