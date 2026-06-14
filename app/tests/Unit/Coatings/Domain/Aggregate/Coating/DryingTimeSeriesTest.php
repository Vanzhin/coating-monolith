<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

class DryingTimeSeriesTest extends TestCase
{
    public function testEmptySeriesThrows(): void
    {
        $this->expectException(AppException::class);
        new DryingTimeSeries();
    }

    public function testValidMonotonicProfile(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(5, 30),
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(30, 5),
        );

        $this->assertCount(3, $series->points);
        $temps = array_map(fn(TimeAtTemperature $p) => $p->temperatureAt, $series->points);
        $this->assertSame([5, 20, 30], $temps);
    }

    public function testSortsUnorderedInput(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(30, 5),
            new TimeAtTemperature(5, 30),
            new TimeAtTemperature(20, 10),
        );

        $temps = array_map(fn(TimeAtTemperature $p) => $p->temperatureAt, $series->points);
        $this->assertSame([5, 20, 30], $temps);
    }

    public function testDuplicateTemperatureThrows(): void
    {
        $this->expectException(AppException::class);
        new DryingTimeSeries(
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(20, 5),
        );
    }

    public function testNonMonotonicThrows(): void
    {
        $this->expectException(AppException::class);
        new DryingTimeSeries(
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(30, 20),
        );
    }

    public function testEqualTimesAllowed(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(25, 10),
        );
        $this->assertCount(2, $series->points);
    }

    public function testSinglePointAllowed(): void
    {
        $series = new DryingTimeSeries(new TimeAtTemperature(20, 10));
        $this->assertCount(1, $series->points);
    }

    public function testGetPointExact(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(30, 5),
        );

        $point = $series->getPoint(20);
        $this->assertNotNull($point);
        $this->assertSame(10, $point->timeInMinutes);
        $this->assertFalse($point->isCalculated);
    }

    public function testInterpolatesBetweenPoints(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(30, 5),
        );

        $point = $series->getPoint(25);

        $this->assertNotNull($point);
        // 7.5 округляется до 8 (PHP round half away from zero).
        $this->assertSame(8, $point->timeInMinutes);
        $this->assertTrue($point->isCalculated);
    }

    public function testGetPointOutOfRangeReturnsNull(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(30, 5),
        );

        $this->assertNull($series->getPoint(10));
        $this->assertNull($series->getPoint(35));
    }

    public function testJsonSerializeUsesSnakeCase(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(30, 5),
        );

        // jsonSerialize() возвращает list<TimeAtTemperature>; json_encode рекурсивно
        // вызовет TimeAtTemperature::jsonSerialize() и получится snake_case-структура.
        $this->assertSame(
            [
                ['temperature_at' => 20, 'time_in_minutes' => 10, 'is_calculated' => false],
                ['temperature_at' => 30, 'time_in_minutes' => 5, 'is_calculated' => false],
            ],
            json_decode(json_encode($series), true),
        );
    }
}
