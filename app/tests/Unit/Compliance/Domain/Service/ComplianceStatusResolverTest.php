<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Service;

use App\Compliance\Domain\Service\ComplianceStatusResolver;
use App\Compliance\Domain\Type\ComplianceBucket;
use App\Compliance\Domain\Type\ComplianceStatus;
use PHPUnit\Framework\TestCase;

/**
 * Единый ладдер состояния: {@see ComplianceStatusResolver::bucketFor()} (канонический 4-бакет) и производный
 * {@see ComplianceStatusResolver::statusFor()} (трёхцветный). Горизонт «подходит срок» — DUE_SOON_DAYS (60).
 */
final class ComplianceStatusResolverTest extends TestCase
{
    private ComplianceStatusResolver $r;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->r = new ComplianceStatusResolver();
        $this->now = new \DateTimeImmutable('2026-06-01'); // +60 дней = 2026-07-31
    }

    public function test_not_signed_is_missing_regardless_of_dates(): void
    {
        self::assertSame(ComplianceBucket::Missing, $this->r->bucketFor(false, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'), $this->now));
    }

    public function test_signed_but_never_fulfilled_is_missing(): void
    {
        self::assertSame(ComplianceBucket::Missing, $this->r->bucketFor(true, null, null, $this->now));
        self::assertSame(ComplianceBucket::Missing, $this->r->bucketFor(true, null, new \DateTimeImmutable('2027-01-01'), $this->now));
    }

    public function test_fulfilled_without_next_due_is_ok(): void
    {
        self::assertSame(ComplianceBucket::Ok, $this->r->bucketFor(true, new \DateTimeImmutable('2026-01-01'), null, $this->now));
    }

    public function test_past_due_is_overdue(): void
    {
        self::assertSame(ComplianceBucket::Overdue, $this->r->bucketFor(true, new \DateTimeImmutable('2025-05-01'), new \DateTimeImmutable('2026-05-20'), $this->now));
    }

    public function test_within_horizon_is_soon(): void
    {
        self::assertSame(ComplianceBucket::Soon, $this->r->bucketFor(true, new \DateTimeImmutable('2025-07-01'), new \DateTimeImmutable('2026-07-01'), $this->now));
    }

    public function test_boundary_60_days_is_soon(): void
    {
        // ровно now+60 = 2026-07-31 — ещё Soon (<=)
        self::assertSame(ComplianceBucket::Soon, $this->r->bucketFor(true, new \DateTimeImmutable('2025-07-31'), new \DateTimeImmutable('2026-07-31'), $this->now));
    }

    public function test_just_past_horizon_is_ok(): void
    {
        // now+61 = 2026-08-01 — уже Ok
        self::assertSame(ComplianceBucket::Ok, $this->r->bucketFor(true, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-08-01'), $this->now));
    }

    public function test_far_future_is_ok(): void
    {
        self::assertSame(ComplianceBucket::Ok, $this->r->bucketFor(true, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'), $this->now));
    }

    public function test_under_issued_is_missing_even_if_in_date(): void
    {
        // активно, выдано вчера, срок далеко, НО на руках 1 из нормы 2 → недовыдано
        self::assertSame(ComplianceBucket::Missing, $this->r->bucketFor(true, new \DateTimeImmutable('2026-05-31'), new \DateTimeImmutable('2027-05-31'), $this->now, 2.0, 1.0));
    }

    public function test_held_meets_norm_in_date_is_ok(): void
    {
        self::assertSame(ComplianceBucket::Ok, $this->r->bucketFor(true, new \DateTimeImmutable('2026-05-31'), new \DateTimeImmutable('2027-05-31'), $this->now, 2.0, 2.0));
    }

    public function test_status_is_derived_from_bucket(): void
    {
        // Missing/Overdue → Red, Soon → Yellow, Ok → Green (тот же вход, что и bucketFor выше)
        self::assertSame(ComplianceStatus::Red, $this->r->statusFor(false, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'), $this->now));
        self::assertSame(ComplianceStatus::Red, $this->r->statusFor(true, new \DateTimeImmutable('2025-05-01'), new \DateTimeImmutable('2026-05-20'), $this->now));
        self::assertSame(ComplianceStatus::Yellow, $this->r->statusFor(true, new \DateTimeImmutable('2025-07-01'), new \DateTimeImmutable('2026-07-01'), $this->now));
        self::assertSame(ComplianceStatus::Green, $this->r->statusFor(true, new \DateTimeImmutable('2026-01-01'), null, $this->now));
        self::assertSame(ComplianceStatus::Red, $this->r->statusFor(true, new \DateTimeImmutable('2026-05-31'), new \DateTimeImmutable('2027-05-31'), $this->now, 2.0, 1.0));
    }
}
