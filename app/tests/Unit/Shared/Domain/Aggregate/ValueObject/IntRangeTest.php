<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Domain\Aggregate\ValueObject\IntRange;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class IntRangeTest extends TestCase
{
    public function test_typed_boundaries_length_and_contains(): void
    {
        $range = new IntRange(-2, 3);

        self::assertSame(-2, $range->start());
        self::assertSame(3, $range->end());
        self::assertSame(5, $range->length());
        self::assertTrue($range->contains(-2));
        self::assertTrue($range->contains(0));
        self::assertTrue($range->contains(3));
        self::assertFalse($range->contains(-3));
        self::assertFalse($range->contains(4));
    }

    public function test_reversed_range_is_rejected(): void
    {
        $this->expectException(AppException::class);
        new IntRange(2, 1);
    }
}
