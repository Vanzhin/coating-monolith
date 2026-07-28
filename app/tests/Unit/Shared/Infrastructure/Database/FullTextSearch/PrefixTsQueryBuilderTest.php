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

    public function testBuildWithAndConjunction(): void
    {
        $result = $this->builder->build('быстросох эпоксидн');
        self::assertSame('быстросох:* & эпоксидн:*', $result);
    }

    public function testBuildWithOrConjunction(): void
    {
        $result = $this->builder->build('вода этанол', PrefixTsQueryBuilder::CONJUNCTION_OR);
        self::assertSame('вода:* | этанол:*', $result);
    }

    public function testSanitizationRemovesMeta(): void
    {
        $result = $this->builder->build('!!! *foo* bar');
        self::assertSame('foo:* & bar:*', $result);
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        $result = $this->builder->build('');
        self::assertSame('', $result);
    }

    public function testWhitespaceOnlyReturnsEmpty(): void
    {
        $result = $this->builder->build('   ');
        self::assertSame('', $result);
    }

    public function testSeparatorsComa(): void
    {
        $result = $this->builder->build('foo, bar; baz');
        self::assertSame('foo:* & bar:* & baz:*', $result);
    }

    public function testSeparatorsDotAndDash(): void
    {
        $result = $this->builder->build('foo-bar.baz');
        self::assertSame('foo:* & bar:* & baz:*', $result);
    }

    public function testUnknownConjunctionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder->build('test', '~');
    }

    public function testMixedMetaAndWords(): void
    {
        $result = $this->builder->build('hello&world|test');
        self::assertSame('hello:* & world:* & test:*', $result);
    }

    public function testSingleWord(): void
    {
        $result = $this->builder->build('word');
        self::assertSame('word:*', $result);
    }
}
