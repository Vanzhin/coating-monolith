<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Type;

use App\Compliance\Domain\Type\ComplianceBucket;
use App\Compliance\Domain\Type\ComplianceStatus;
use PHPUnit\Framework\TestCase;

final class ComplianceStatusTest extends TestCase
{
    public function test_severity_order(): void
    {
        self::assertLessThan(ComplianceStatus::Yellow->severity(), ComplianceStatus::Green->severity());
        self::assertLessThan(ComplianceStatus::Red->severity(), ComplianceStatus::Yellow->severity());
    }

    public function test_worse_of_red_dominates(): void
    {
        self::assertSame(ComplianceStatus::Red, ComplianceStatus::worseOf(ComplianceStatus::Green, ComplianceStatus::Red));
        self::assertSame(ComplianceStatus::Red, ComplianceStatus::worseOf(ComplianceStatus::Red, ComplianceStatus::Yellow));
        self::assertSame(ComplianceStatus::Yellow, ComplianceStatus::worseOf(ComplianceStatus::Green, ComplianceStatus::Yellow));
        self::assertSame(ComplianceStatus::Green, ComplianceStatus::worseOf(ComplianceStatus::Green, ComplianceStatus::Green));
    }

    public function test_titles_present(): void
    {
        foreach (ComplianceStatus::cases() as $s) {
            self::assertNotSame('', $s->title());
        }
    }

    public function test_from_bucket_maps_overdue_and_missing_to_red(): void
    {
        self::assertSame(ComplianceStatus::Green, ComplianceStatus::fromBucket(ComplianceBucket::Ok));
        self::assertSame(ComplianceStatus::Yellow, ComplianceStatus::fromBucket(ComplianceBucket::Soon));
        self::assertSame(ComplianceStatus::Red, ComplianceStatus::fromBucket(ComplianceBucket::Overdue));
        self::assertSame(ComplianceStatus::Red, ComplianceStatus::fromBucket(ComplianceBucket::Missing));
    }
}
