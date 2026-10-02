<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Application\ReadModel;

use App\Compliance\Application\ReadModel\ComplianceBucket;
use App\Compliance\Application\ReadModel\ComplianceBucketResolver;
use App\Compliance\Domain\Type\ComplianceType;
use PHPUnit\Framework\TestCase;

final class ComplianceBucketResolverTest extends TestCase
{
    private ComplianceBucketResolver $resolver;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->resolver = new ComplianceBucketResolver();
        $this->now = new \DateTimeImmutable('2026-06-01');
    }

    public function test_not_signed_is_missing(): void
    {
        self::assertSame(ComplianceBucket::Missing, $this->resolver->bucketFor(false, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'), $this->now));
    }

    public function test_signed_but_never_fulfilled_is_missing(): void
    {
        self::assertSame(ComplianceBucket::Missing, $this->resolver->bucketFor(true, null, null, $this->now));
    }

    public function test_fulfilled_no_due_is_ok(): void
    {
        self::assertSame(ComplianceBucket::Ok, $this->resolver->bucketFor(true, new \DateTimeImmutable('2026-01-01'), null, $this->now));
    }

    public function test_past_due_is_overdue(): void
    {
        self::assertSame(ComplianceBucket::Overdue, $this->resolver->bucketFor(true, new \DateTimeImmutable('2025-05-01'), new \DateTimeImmutable('2026-05-20'), $this->now));
    }

    public function test_within_14_days_is_soon(): void
    {
        self::assertSame(ComplianceBucket::Soon, $this->resolver->bucketFor(true, new \DateTimeImmutable('2025-06-10'), new \DateTimeImmutable('2026-06-10'), $this->now));
    }

    public function test_boundary_14_days_is_soon(): void
    {
        self::assertSame(ComplianceBucket::Soon, $this->resolver->bucketFor(true, new \DateTimeImmutable('2025-06-15'), new \DateTimeImmutable('2026-06-15'), $this->now));
    }

    public function test_far_future_is_ok(): void
    {
        self::assertSame(ComplianceBucket::Ok, $this->resolver->bucketFor(true, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-01'), $this->now));
    }

    public function test_worst_of_missing_dominates(): void
    {
        self::assertSame(ComplianceBucket::Missing, ComplianceBucket::worseOf(ComplianceBucket::Overdue, ComplianceBucket::Missing));
        self::assertSame(ComplianceBucket::Overdue, ComplianceBucket::worseOf(ComplianceBucket::Soon, ComplianceBucket::Overdue));
        self::assertSame(ComplianceBucket::Soon, ComplianceBucket::worseOf(ComplianceBucket::Ok, ComplianceBucket::Soon));
    }

    public function test_missing_label_is_type_specific(): void
    {
        self::assertSame('не выдано', ComplianceBucket::Missing->label(ComplianceType::Material));
        self::assertSame('не пройдено', ComplianceBucket::Missing->label(ComplianceType::NonMaterial));
    }
}
