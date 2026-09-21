<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class DateTimeIntervalTest extends TestCase
{
    public function test_accepts_from_before_to(): void
    {
        $i = new DateTimeInterval(new \DateTimeImmutable('2026-07-30'), new \DateTimeImmutable('2026-08-04'));
        self::assertSame('2026-07-30', $i->getFrom()?->format('Y-m-d'));
        self::assertSame('2026-08-04', $i->getTo()?->format('Y-m-d'));
    }

    public function test_accepts_open_ended_from_only(): void
    {
        $i = new DateTimeInterval(new \DateTimeImmutable('2026-07-30'), null);
        self::assertNotNull($i->getFrom());
        self::assertNull($i->getTo());
    }

    public function test_accepts_open_ended_to_only(): void
    {
        $i = new DateTimeInterval(null, new \DateTimeImmutable('2026-08-04'));
        self::assertNull($i->getFrom());
        self::assertNotNull($i->getTo());
    }

    public function test_accepts_equal_bounds(): void
    {
        $day = new \DateTimeImmutable('2026-08-04');
        $i = new DateTimeInterval($day, $day);
        self::assertEquals($i->getFrom(), $i->getTo());
    }

    public function test_rejects_both_null(): void
    {
        $this->expectException(AppException::class);
        new DateTimeInterval(null, null);
    }

    public function test_rejects_from_after_to(): void
    {
        $this->expectException(AppException::class);
        new DateTimeInterval(new \DateTimeImmutable('2026-08-04'), new \DateTimeImmutable('2026-07-30'));
    }

    public function test_json_round_trip(): void
    {
        $i = new DateTimeInterval(new \DateTimeImmutable('2026-07-30 00:00:00'), new \DateTimeImmutable('2026-08-04 00:00:00'));
        $back = DateTimeInterval::fromArray($i->jsonSerialize());
        self::assertEquals($i->getFrom(), $back->getFrom());
        self::assertEquals($i->getTo(), $back->getTo());
    }
}
