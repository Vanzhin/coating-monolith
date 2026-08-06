<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Helper;

use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Helper\QueryParams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class QueryParamsTest extends TestCase
{
    private QueryParams $qp;

    protected function setUp(): void
    {
        $this->qp = new QueryParams();
    }

    public function test_nullable_int_returns_null_for_missing_and_empty(): void
    {
        $request = Request::create('/', 'GET', ['a' => '', 'b' => '  ']);

        self::assertNull($this->qp->nullableInt($request, 'a'));
        self::assertNull($this->qp->nullableInt($request, 'b'));
        self::assertNull($this->qp->nullableInt($request, 'missing'));
    }

    public function test_nullable_int_casts_present_value(): void
    {
        $request = Request::create('/', 'GET', ['n' => '42']);

        self::assertSame(42, $this->qp->nullableInt($request, 'n'));
    }

    public function test_positive_int_returns_value_when_positive(): void
    {
        $request = Request::create('/', 'GET', ['n' => '5']);

        self::assertSame(5, $this->qp->positiveInt($request, 'n'));
    }

    public function test_positive_int_returns_null_for_zero(): void
    {
        $request = Request::create('/', 'GET', ['n' => '0']);

        self::assertNull($this->qp->positiveInt($request, 'n'));
    }

    public function test_positive_int_returns_null_for_negative(): void
    {
        $request = Request::create('/', 'GET', ['n' => '-1']);

        self::assertNull($this->qp->positiveInt($request, 'n'));
    }

    public function test_positive_int_returns_null_when_missing(): void
    {
        self::assertNull($this->qp->positiveInt(Request::create('/', 'GET'), 'missing'));
    }

    public function test_string_collection_filters_and_dedups(): void
    {
        $request = Request::create('/', 'GET', ['ids' => ['EP', 'ZZZ', 'EP', 'AY']]);

        $valid = $this->qp->stringCollection(
            $request,
            'ids',
            static fn (string $v): bool => in_array($v, ['EP', 'AY'], true),
            unique: true,
        );

        self::assertSame(['EP', 'AY'], $valid->getList());
    }

    public function test_string_collection_without_validator_keeps_all_strings(): void
    {
        $request = Request::create('/', 'GET', ['ids' => ['a', 'b']]);

        self::assertSame(['a', 'b'], $this->qp->stringCollection($request, 'ids')->getList());
    }

    public function test_int_range_applies_multiplier(): void
    {
        $request = Request::create('/', 'GET', ['from' => '2', 'to' => '3']);

        $range = $this->qp->intRange($request, 'from', 'to', 60);

        self::assertNotNull($range);
        self::assertSame(120, $range->from);
        self::assertSame(180, $range->to);
    }

    public function test_int_range_drops_inverted_by_default(): void
    {
        $request = Request::create('/', 'GET', ['from' => '10', 'to' => '5']);

        self::assertNull($this->qp->intRange($request, 'from', 'to'));
    }

    public function test_int_range_throws_on_inverted_when_not_dropping(): void
    {
        $request = Request::create('/', 'GET', ['from' => '10', 'to' => '5']);

        $this->expectException(AppException::class);
        $this->qp->intRange($request, 'from', 'to', 1, dropInverted: false);
    }

    public function test_int_range_null_when_both_missing(): void
    {
        self::assertNull($this->qp->intRange(Request::create('/', 'GET'), 'from', 'to'));
    }
}
