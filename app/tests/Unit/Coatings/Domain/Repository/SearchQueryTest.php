<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Repository;

use App\Coatings\Domain\Repository\SearchQuery;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class SearchQueryTest extends TestCase
{
    public function testTryFromStringReturnsNullForNullInput(): void
    {
        $this->assertNull(SearchQuery::tryFromString(null));
    }

    public function testTryFromStringReturnsNullForEmptyOrWhitespace(): void
    {
        $this->assertNull(SearchQuery::tryFromString(''));
        $this->assertNull(SearchQuery::tryFromString('   '));
    }

    public function testTryFromStringTrimsValue(): void
    {
        $query = SearchQuery::tryFromString('  эпоксидная   ');
        $this->assertNotNull($query);
        $this->assertSame('эпоксидная', $query->value);
    }

    public function testShortQueryThrows(): void
    {
        $this->expectException(AppException::class);
        SearchQuery::tryFromString('ва');
    }

    public function testTooLongQueryThrows(): void
    {
        $this->expectException(AppException::class);
        SearchQuery::tryFromString(str_repeat('а', 51));
    }

    public function testHasSingleWordTrueForOneToken(): void
    {
        $this->assertTrue(SearchQuery::tryFromString('эпоксидная')->hasSingleWord());
    }

    public function testHasSingleWordFalseForMultipleTokens(): void
    {
        $this->assertFalse(SearchQuery::tryFromString('быстросох эпоксидн')->hasSingleWord());
    }

    public function testHasSingleWordFalseWhenSeparatorSplits(): void
    {
        // dash/dot/semicolon тоже разбивают: те же правила что и tsquery-builder.
        $this->assertFalse(SearchQuery::tryFromString('a-b')->hasSingleWord());
        $this->assertFalse(SearchQuery::tryFromString('a.b')->hasSingleWord());
        $this->assertFalse(SearchQuery::tryFromString('a; b')->hasSingleWord());
    }

    public function testWordsReturnsList(): void
    {
        $q = SearchQuery::tryFromString('быстросох эпоксидн');
        $this->assertSame(['быстросох', 'эпоксидн'], $q->words());
    }
}
