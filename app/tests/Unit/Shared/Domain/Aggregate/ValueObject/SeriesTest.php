<?php
declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Unit\Shared\Domain\Aggregate\ValueObject\Fixtures\IntSeriesPoint;
use App\Tests\Unit\Shared\Domain\Aggregate\ValueObject\Fixtures\TestIntSeries;
use PHPUnit\Framework\TestCase;

class SeriesTest extends TestCase
{
    public function testEmptySeriesThrows(): void
    {
        $this->expectException(AppException::class);
        new TestIntSeries();
    }

    public function testDuplicateKeyIsOverwrittenSilently(): void
    {
        $series = new TestIntSeries(
            new IntSeriesPoint(10, 100),
            new IntSeriesPoint(10, 200),
        );
        $this->assertCount(1, $series->points);
        $this->assertSame(200, $series->points[10]->getValue());
    }

    public function testAutoSortByKey(): void
    {
        $series = new TestIntSeries(
            new IntSeriesPoint(30, 25),
            new IntSeriesPoint(10, 100),
            new IntSeriesPoint(20, 50),
        );
        $this->assertSame([10, 20, 30], array_keys($series->points));
    }

    public function testGetPointExact(): void
    {
        $series = new TestIntSeries(
            new IntSeriesPoint(10, 100),
            new IntSeriesPoint(20, 50),
        );
        $point = $series->getPoint(10);
        $this->assertNotNull($point);
        $this->assertSame(10, $point->getKey());
        $this->assertSame(100, $point->getValue());
        $this->assertFalse($point->isCalculated());
    }

    public function testGetPointInterpolated(): void
    {
        $series = new TestIntSeries(
            new IntSeriesPoint(20, 50),
            new IntSeriesPoint(30, 25),
        );
        $point = $series->getPoint(25);
        $this->assertNotNull($point);
        $this->assertSame(25, $point->getKey());
        $this->assertEqualsWithDelta(37, $point->getValue(), 1.0);
        $this->assertTrue($point->isCalculated());
    }

    public function testGetPointOutOfRangeReturnsNull(): void
    {
        $series = new TestIntSeries(
            new IntSeriesPoint(10, 100),
            new IntSeriesPoint(20, 50),
        );
        $this->assertNull($series->getPoint(5));
        $this->assertNull($series->getPoint(25));
    }

    public function testGetRangeKeepsKeysIncludingOutOfRange(): void
    {
        $series = new TestIntSeries(
            new IntSeriesPoint(10, 100),
            new IntSeriesPoint(20, 50),
            new IntSeriesPoint(30, 25),
        );
        $range = $series->getRange(10, 50, 10);

        $this->assertCount(5, $range);
        $this->assertNotNull($range[10]);
        $this->assertNotNull($range[20]);
        $this->assertNotNull($range[30]);
        $this->assertNull($range[40]);
        $this->assertNull($range[50]);
    }

    public function testMapReturnsNewSeriesWithoutMutatingOriginal(): void
    {
        $original = new TestIntSeries(
            new IntSeriesPoint(10, 100),
            new IntSeriesPoint(20, 50),
        );
        $doubled = $original->map(fn(int $value) => $value * 2);

        $this->assertNotSame($original, $doubled);
        $this->assertSame(200, $doubled->points[10]->getValue());
        $this->assertSame(100, $doubled->points[20]->getValue());
        $this->assertSame(100, $original->points[10]->getValue());
    }

    public function testMultiplyShortcut(): void
    {
        $series = new TestIntSeries(new IntSeriesPoint(10, 100));
        $tripled = $series->multiply(3);
        $this->assertSame(300, $tripled->points[10]->getValue());
    }

    public function testWithPointAddsNewKey(): void
    {
        $series = new TestIntSeries(
            new IntSeriesPoint(10, 100),
            new IntSeriesPoint(30, 25),
        );
        $updated = $series->withPoint(new IntSeriesPoint(20, 50));

        $this->assertSame([10, 20, 30], array_keys($updated->points));
        $this->assertCount(2, $series->points);
    }

    public function testWithPointReplacesExistingKey(): void
    {
        $series = new TestIntSeries(new IntSeriesPoint(20, 10));
        $updated = $series->withPoint(new IntSeriesPoint(20, 15));

        $this->assertCount(1, $updated->points);
        $this->assertSame(15, $updated->points[20]->getValue());
    }
}
