<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\UseCase\Query;

use App\Coatings\Application\UseCase\Query\SearchCoatingSystemsForSuggest\SearchCoatingSystemsForSuggestQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystemsForSuggest\SearchCoatingSystemsForSuggestQueryHandler;
use App\Shared\Infrastructure\Database\FullTextSearch\PrefixTsQueryBuilder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SearchCoatingSystemsForSuggestQueryHandlerTest extends TestCase
{
    private Connection&MockObject $conn;
    private SearchCoatingSystemsForSuggestQueryHandler $handler;

    protected function setUp(): void
    {
        $this->conn = $this->createMock(Connection::class);
        $this->handler = new SearchCoatingSystemsForSuggestQueryHandler($this->conn, new PrefixTsQueryBuilder());
    }

    public function test_handler_does_not_crash_on_stop_word_only_query(): void
    {
        // "---" produces '' from PrefixTsQueryBuilder — all chars are tsquery meta
        $this->conn->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains('1 = 0'),
                self::callback(static fn (array $params) => !isset($params['tsquery']) && !isset($params['lang'])),
                self::anything(),
            )
            ->willReturn([]);

        $result = ($this->handler)(new SearchCoatingSystemsForSuggestQuery('---', 10));

        self::assertSame([], $result->items);
    }

    public function test_empty_query_returns_empty_without_db_call(): void
    {
        $this->conn->expects(self::never())->method('fetchAllAssociative');

        $result = ($this->handler)(new SearchCoatingSystemsForSuggestQuery('   ', 10));

        self::assertSame([], $result->items);
    }
}
