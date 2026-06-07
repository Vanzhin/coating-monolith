<?php
declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\ValueObject\Fixtures;

use App\Shared\Domain\Aggregate\ValueObject\Series;
use App\Shared\Domain\Aggregate\ValueObject\SeriesPoint;

final readonly class TestIntSeries extends Series
{
    protected function validate(): void
    {
        // no extra rules for the fixture
    }

    protected function createPoint(int|float $key, int|float $value, bool $isCalculated): SeriesPoint
    {
        return new IntSeriesPoint((int) $key, (int) $value, $isCalculated);
    }
}
