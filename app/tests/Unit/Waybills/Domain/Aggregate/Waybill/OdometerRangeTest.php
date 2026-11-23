<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Waybill;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Waybill\OdometerRange;
use PHPUnit\Framework\TestCase;

final class OdometerRangeTest extends TestCase
{
    public function test_zero_is_allowed(): void
    {
        $range = new OdometerRange(0, 0);

        self::assertSame(0, $range->start());
        self::assertSame(0, $range->end());
        self::assertSame(0, $range->distance());
    }

    public function test_distance_is_calculated_from_typed_boundaries(): void
    {
        $range = new OdometerRange(100, 250);

        self::assertSame(150, $range->distance());
        self::assertSame(150, $range->length());
    }

    public function test_negative_start_is_rejected(): void
    {
        $this->expectException(AppException::class);
        new OdometerRange(-1, 10);
    }

    public function test_end_before_start_is_rejected(): void
    {
        $this->expectException(AppException::class);
        new OdometerRange(10, 9);
    }
}
