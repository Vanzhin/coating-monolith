<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Type;

use App\Compliance\Domain\Type\PeriodUnit;
use PHPUnit\Framework\TestCase;

final class PeriodUnitTest extends TestCase
{
    public function test_add_to(): void
    {
        $base = new \DateTimeImmutable('2024-01-01');
        self::assertSame('2026-01-01', PeriodUnit::Year->addTo($base, 2)->format('Y-m-d'));
        self::assertSame('2024-03-01', PeriodUnit::Month->addTo($base, 2)->format('Y-m-d'));
    }

    public function test_year_plural(): void
    {
        self::assertSame('год', PeriodUnit::Year->pluralFor(1));
        self::assertSame('года', PeriodUnit::Year->pluralFor(2));
        self::assertSame('лет', PeriodUnit::Year->pluralFor(5));
        self::assertSame('лет', PeriodUnit::Year->pluralFor(11));
        self::assertSame('год', PeriodUnit::Year->pluralFor(21));
    }

    public function test_month_plural(): void
    {
        self::assertSame('месяц', PeriodUnit::Month->pluralFor(1));
        self::assertSame('месяца', PeriodUnit::Month->pluralFor(3));
        self::assertSame('месяцев', PeriodUnit::Month->pluralFor(12));
    }
}
