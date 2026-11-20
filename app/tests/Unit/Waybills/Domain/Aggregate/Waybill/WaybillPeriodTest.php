<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Waybill;

use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use PHPUnit\Framework\TestCase;

final class WaybillPeriodTest extends TestCase
{
    public function test_requires_start_date(): void
    {
        $this->expectException(AppException::class);
        new WaybillPeriod(new DateTimeInterval(to: new \DateTimeImmutable('2026-10-31')));
    }

    public function test_requires_end_date(): void
    {
        $this->expectException(AppException::class);
        new WaybillPeriod(new DateTimeInterval(from: new \DateTimeImmutable('2026-10-01')));
    }

    public function test_period_is_ordered_by_instants_not_local_calendar_dates(): void
    {
        $from = new \DateTimeImmutable('2026-10-02 00:00 +14:00');
        $to = new \DateTimeImmutable('2026-10-01 23:59 -12:00');
        $period = WaybillPeriod::fromDates($from, $to);

        self::assertSame($from, $period->from());
        self::assertSame($to, $period->to());
    }

    public function test_date_factory_preserves_time_and_timezone(): void
    {
        $from = new \DateTimeImmutable('2026-10-01 08:00 +03:00');
        $to = new \DateTimeImmutable('2026-10-01 18:00 +03:00');
        $period = WaybillPeriod::fromDates($from, $to);

        self::assertSame($from, $period->from());
        self::assertSame($to, $period->to());
    }

    public function test_period_contains_both_boundary_instants(): void
    {
        $period = $this->period('2026-10-01 18:00', '2026-10-31 08:00');
        self::assertTrue($period->contains(new \DateTimeImmutable('2026-10-01 18:00')));
        self::assertTrue($period->contains(new \DateTimeImmutable('2026-10-31 08:00')));
        self::assertFalse($period->contains(new \DateTimeImmutable('2026-10-01 00:00')));
        self::assertFalse($period->contains(new \DateTimeImmutable('2026-10-31 23:59')));
        self::assertFalse($period->contains(new \DateTimeImmutable('2026-11-01')));
        self::assertFalse($period->contains(new \DateTimeImmutable('2026-09-30')));
    }

    public function test_periods_intersect_when_they_share_a_calendar_day(): void
    {
        $first = $this->period('2026-10-01', '2026-10-15 08:00');
        $second = $this->period('2026-10-15 18:00', '2026-10-31');
        self::assertTrue($first->overlaps($second));
        self::assertTrue($second->overlaps($first));
    }

    public function test_adjacent_periods_do_not_intersect(): void
    {
        $first = $this->period('2026-10-01', '2026-10-15');
        $second = $this->period('2026-10-16', '2026-10-31');
        self::assertFalse($first->overlaps($second));
        $second->assertDoesNotOverlap($first);
    }

    public function test_intersection_with_any_other_period_is_rejected(): void
    {
        $this->expectException(AppException::class);
        $this->period('2026-10-10', '2026-10-20')->assertDoesNotOverlap(
            $this->period('2026-10-01', '2026-10-09'),
            $this->period('2026-10-20', '2026-10-31'),
        );
    }

    public function test_new_period_follows_all_approved_periods(): void
    {
        $period = $this->period('2026-11-01', '2026-11-30');
        $period->assertFollows(
            $this->period('2026-09-01', '2026-09-30'),
            $this->period('2026-10-01', '2026-10-31'),
        );
        self::assertSame('2026-11-01', $period->from()->format('Y-m-d'));
    }

    public function test_past_nonoverlapping_period_does_not_follow_approved_period(): void
    {
        $this->expectException(AppException::class);
        $this->period('2026-09-01', '2026-09-30')->assertFollows($this->period('2026-10-01', '2026-10-31'));
    }

    public function test_period_cannot_begin_on_last_approved_day(): void
    {
        $this->expectException(AppException::class);
        $this->period('2026-10-31', '2026-11-30')->assertFollows($this->period('2026-10-01', '2026-10-31'));
    }

    private function period(string $from, string $to): WaybillPeriod
    {
        return WaybillPeriod::fromDates(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }
}
