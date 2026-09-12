<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Domain\Aggregate\ValueObject\Duration;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    public function test_from_parts_sums_to_minutes(): void
    {
        self::assertSame(1590, Duration::fromParts(1, 2, 30)->minutes());
        self::assertSame(0, Duration::fromParts(0, 0, 0)->minutes());
        self::assertSame(Duration::MINUTES_PER_DAY, Duration::fromParts(1, 0, 0)->minutes());
        self::assertSame(Duration::MINUTES_PER_HOUR, Duration::fromParts(0, 1, 0)->minutes());
    }

    public function test_to_parts_decomposes_minutes(): void
    {
        self::assertSame(['days' => 1, 'hours' => 2, 'minutes' => 30], Duration::ofMinutes(1590)->toParts());
        self::assertSame(['days' => 0, 'hours' => 0, 'minutes' => 12], Duration::ofMinutes(12)->toParts());
        self::assertSame(['days' => 10, 'hours' => 0, 'minutes' => 0], Duration::ofMinutes(14400)->toParts());
    }

    public function test_parts_round_trip(): void
    {
        foreach ([0, 12, 60, 1440, 1590, 100000] as $minutes) {
            $parts = Duration::ofMinutes($minutes)->toParts();
            self::assertSame(
                $minutes,
                Duration::fromParts($parts['days'], $parts['hours'], $parts['minutes'])->minutes(),
            );
        }
    }

    public function test_canonical_factors(): void
    {
        self::assertSame(60, Duration::MINUTES_PER_HOUR);
        self::assertSame(1440, Duration::MINUTES_PER_DAY);
    }
}
