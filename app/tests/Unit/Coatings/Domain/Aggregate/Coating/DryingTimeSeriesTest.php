<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

class DryingTimeSeriesTest extends TestCase
{
    public function testValidMonotonicProfile(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(5, 30.0),
            new TimeAtTemperature(20, 10.0),
            new TimeAtTemperature(30, 5.0),
        );

        $this->assertCount(3, $series->points);
        $this->assertSame([5, 20, 30], array_keys($series->points));
    }

    public function testNonMonotonicThrows(): void
    {
        $this->expectException(AppException::class);
        new DryingTimeSeries(
            new TimeAtTemperature(20, 10.0),
            new TimeAtTemperature(30, 20.0),
        );
    }

    public function testEqualTimesAllowed(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 10.0),
            new TimeAtTemperature(25, 10.0),
        );
        $this->assertCount(2, $series->points);
    }

    public function testSinglePointAllowed(): void
    {
        $series = new DryingTimeSeries(new TimeAtTemperature(20, 10.0));
        $this->assertCount(1, $series->points);
    }

    public function testInterpolatesBetweenPoints(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 10.0),
            new TimeAtTemperature(30, 5.0),
        );

        $point = $series->getPoint(25);

        $this->assertNotNull($point);
        $this->assertEqualsWithDelta(7.5, $point->getValue(), 0.01);
        $this->assertTrue($point->isCalculated());
    }

    public function testMultiplyReturnsNewSeriesWithoutMutatingOriginal(): void
    {
        $original = new DryingTimeSeries(new TimeAtTemperature(20, 10.0));
        $boosted = $original->multiply(1.2);

        $this->assertNotSame($original, $boosted);
        $this->assertEqualsWithDelta(12.0, $boosted->points[20]->getValue(), 0.01);
        $this->assertEqualsWithDelta(10.0, $original->points[20]->getValue(), 0.01);
    }

    public function testJsonSerializeUsesSnakeCase(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 10.0),
            new TimeAtTemperature(30, 5.0),
        );

        $this->assertSame(
            [
                ['temperature_at' => 20, 'time_in_minutes' => 10.0, 'is_calculated' => false],
                ['temperature_at' => 30, 'time_in_minutes' => 5.0, 'is_calculated' => false],
            ],
            $series->jsonSerialize(),
        );
    }
}
