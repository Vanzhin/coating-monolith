<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Database\FullTextSearch;

use App\Shared\Infrastructure\Database\FullTextSearch\PrefixTsQueryBuilder;
use PHPUnit\Framework\TestCase;

final class PrefixTsQueryBuilderTest extends TestCase
{
    private PrefixTsQueryBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PrefixTsQueryBuilder();
    }

    public function test_build_with_and_conjunction(): void
    {
        $result = $this->builder->build('быстросох эпоксидн');
        self::assertSame('быстросох:* & эпоксидн:*', $result);
    }

    public function test_build_with_or_conjunction(): void
    {
        $result = $this->builder->build('вода этанол', PrefixTsQueryBuilder::CONJUNCTION_OR);
        self::assertSame('вода:* | этанол:*', $result);
    }

    public function test_sanitization_removes_meta(): void
    {
        $result = $this->builder->build('!!! *foo* bar');
        self::assertSame('foo:* & bar:*', $result);
    }

    public function test_empty_string_returns_empty(): void
    {
        $result = $this->builder->build('');
        self::assertSame('', $result);
    }

    public function test_whitespace_only_returns_empty(): void
    {
        $result = $this->builder->build('   ');
        self::assertSame('', $result);
    }

    public function test_separators_coma(): void
    {
        $result = $this->builder->build('foo, bar; baz');
        self::assertSame('foo:* & bar:* & baz:*', $result);
    }

    public function test_separators_dot_and_dash(): void
    {
        $result = $this->builder->build('foo-bar.baz');
        self::assertSame('foo:* & bar:* & baz:*', $result);
    }

    public function test_unknown_conjunction_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder->build('test', '~');
    }

    public function test_mixed_meta_and_words(): void
    {
        $result = $this->builder->build('hello&world|test');
        self::assertSame('hello:* & world:* & test:*', $result);
    }

    public function test_single_word(): void
    {
        $result = $this->builder->build('word');
        self::assertSame('word:*', $result);
    }
}
