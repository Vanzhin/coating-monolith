<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Type;

use App\Compliance\Domain\Type\ComplianceBucket;
use App\Compliance\Domain\Type\ComplianceType;
use PHPUnit\Framework\TestCase;

final class ComplianceBucketTest extends TestCase
{
    public function test_severity_order(): void
    {
        self::assertSame(0, ComplianceBucket::Ok->severity());
        self::assertSame(1, ComplianceBucket::Soon->severity());
        self::assertSame(2, ComplianceBucket::Overdue->severity());
        self::assertSame(3, ComplianceBucket::Missing->severity());
    }

    public function test_worst_of_missing_dominates(): void
    {
        self::assertSame(ComplianceBucket::Missing, ComplianceBucket::worseOf(ComplianceBucket::Overdue, ComplianceBucket::Missing));
        self::assertSame(ComplianceBucket::Overdue, ComplianceBucket::worseOf(ComplianceBucket::Soon, ComplianceBucket::Overdue));
        self::assertSame(ComplianceBucket::Soon, ComplianceBucket::worseOf(ComplianceBucket::Ok, ComplianceBucket::Soon));
    }

    public function test_is_problem(): void
    {
        self::assertFalse(ComplianceBucket::Ok->isProblem());
        self::assertFalse(ComplianceBucket::Soon->isProblem());
        self::assertTrue(ComplianceBucket::Overdue->isProblem());
        self::assertTrue(ComplianceBucket::Missing->isProblem());
    }

    public function test_missing_label_is_type_specific(): void
    {
        self::assertSame('не выдано', ComplianceBucket::Missing->label(ComplianceType::Material));
        self::assertSame('не пройдено', ComplianceBucket::Missing->label(ComplianceType::NonMaterial));
        self::assertSame('подходит срок', ComplianceBucket::Soon->label(ComplianceType::Material));
    }
}
