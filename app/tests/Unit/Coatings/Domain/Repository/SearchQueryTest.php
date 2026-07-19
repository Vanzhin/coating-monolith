<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Repository;

use App\Coatings\Domain\Repository\SearchQuery;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class SearchQueryTest extends TestCase
{
    public function test_try_from_string_returns_null_for_null_input(): void
    {
        $this->assertNull(SearchQuery::tryFromString(null));
    }

    public function test_try_from_string_returns_null_for_empty_or_whitespace(): void
    {
        $this->assertNull(SearchQuery::tryFromString(''));
        $this->assertNull(SearchQuery::tryFromString('   '));
    }

    public function test_try_from_string_trims_value(): void
    {
        $query = SearchQuery::tryFromString('  эпоксидная   ');
        $this->assertNotNull($query);
        $this->assertSame('эпоксидная', $query->value);
    }

    public function test_short_query_throws(): void
    {
        $this->expectException(AppException::class);
        SearchQuery::tryFromString('ва');
    }

    public function test_too_long_query_throws(): void
    {
        $this->expectException(AppException::class);
        SearchQuery::tryFromString(str_repeat('а', 51));
    }

    public function test_has_single_word_true_for_one_token(): void
    {
        $this->assertTrue(SearchQuery::tryFromString('эпоксидная')->hasSingleWord());
    }

    public function test_has_single_word_false_for_multiple_tokens(): void
    {
        $this->assertFalse(SearchQuery::tryFromString('быстросох эпоксидн')->hasSingleWord());
    }

    public function test_has_single_word_false_when_separator_splits(): void
    {
        // dash/dot/semicolon тоже разбивают: те же правила что и tsquery-builder.
        $this->assertFalse(SearchQuery::tryFromString('a-b')->hasSingleWord());
        $this->assertFalse(SearchQuery::tryFromString('a.b')->hasSingleWord());
        $this->assertFalse(SearchQuery::tryFromString('a; b')->hasSingleWord());
    }

    public function test_words_returns_list(): void
    {
        $q = SearchQuery::tryFromString('быстросох эпоксидн');
        $this->assertSame(['быстросох', 'эпоксидн'], $q->words());
    }
}
