<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Service;

use App\Compliance\Domain\Service\ComplianceStatusResolver;
use App\Compliance\Domain\Type\ComplianceStatus;
use PHPUnit\Framework\TestCase;

final class ComplianceStatusResolverTest extends TestCase
{
    private ComplianceStatusResolver $r;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->r = new ComplianceStatusResolver();
        $this->now = new \DateTimeImmutable('2026-06-01');
    }

    public function test_unsigned_document_is_red_regardless_of_dates(): void
    {
        // Документ не подписан — требование не исполнено, даже если выдачи есть и срок далеко.
        self::assertSame(ComplianceStatus::Red, $this->r->statusFor(false, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'), $this->now));
    }

    public function test_never_fulfilled_is_red(): void
    {
        self::assertSame(ComplianceStatus::Red, $this->r->statusFor(true, null, null, $this->now));
        self::assertSame(ComplianceStatus::Red, $this->r->statusFor(true, null, new \DateTimeImmutable('2027-01-01'), $this->now));
    }

    public function test_fulfilled_without_next_due_is_green(): void
    {
        self::assertSame(ComplianceStatus::Green, $this->r->statusFor(true, new \DateTimeImmutable('2026-01-01'), null, $this->now));
    }

    public function test_overdue_is_red(): void
    {
        self::assertSame(ComplianceStatus::Red, $this->r->statusFor(true, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2026-05-01'), $this->now));
    }

    public function test_due_soon_is_yellow(): void
    {
        self::assertSame(ComplianceStatus::Yellow, $this->r->statusFor(true, new \DateTimeImmutable('2025-06-11'), new \DateTimeImmutable('2026-06-11'), $this->now));
    }

    public function test_boundary_14_days_is_yellow(): void
    {
        self::assertSame(ComplianceStatus::Yellow, $this->r->statusFor(true, new \DateTimeImmutable('2025-06-15'), new \DateTimeImmutable('2026-06-15'), $this->now));
    }

    public function test_far_future_is_green(): void
    {
        self::assertSame(ComplianceStatus::Green, $this->r->statusFor(true, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-08-01'), $this->now));
    }

    public function test_under_issued_is_red_even_if_in_date(): void
    {
        $now = new \DateTimeImmutable('2026-06-01');
        // активно, выдано вчера, срок далеко, НО на руках 1 из нормы 2
        $status = $this->r->statusFor(true, new \DateTimeImmutable('2026-05-31'), new \DateTimeImmutable('2027-05-31'), $now, 2.0, 1.0);
        self::assertSame(ComplianceStatus::Red, $status);
    }

    public function test_held_meets_norm_in_date_is_green(): void
    {
        $now = new \DateTimeImmutable('2026-06-01');
        $status = $this->r->statusFor(true, new \DateTimeImmutable('2026-05-31'), new \DateTimeImmutable('2027-05-31'), $now, 2.0, 2.0);
        self::assertSame(ComplianceStatus::Green, $status);
    }
}
