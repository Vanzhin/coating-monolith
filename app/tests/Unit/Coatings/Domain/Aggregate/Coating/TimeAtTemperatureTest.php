<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Shared\Infrastructure\Exception\AppException;
use Carbon\CarbonInterval;
use PHPUnit\Framework\TestCase;

class TimeAtTemperatureTest extends TestCase
{
    public function testValidConstruction(): void
    {
        $point = new TimeAtTemperature(20, 10);

        $this->assertSame(20, $point->temperatureAt);
        $this->assertSame(10, $point->timeInMinutes);
        $this->assertFalse($point->isCalculated);
    }

    public function testTimeInMinutesIsInt(): void
    {
        $point = new TimeAtTemperature(20, 10);
        $this->assertSame(10, $point->timeInMinutes);
    }

    public function testNegativeMinutesThrow(): void
    {
        $this->expectException(AppException::class);
        new TimeAtTemperature(20, -1);
    }

    public function testNegativeTemperatureIsAllowed(): void
    {
        $point = new TimeAtTemperature(-10, 60);
        $this->assertSame(-10, $point->temperatureAt);
    }

    public function testIsCalculatedFlag(): void
    {
        $point = new TimeAtTemperature(20, 10, isCalculated: true);
        $this->assertTrue($point->isCalculated);
    }

    public function testGetIntervalReturnsCarbonInterval(): void
    {
        $point = new TimeAtTemperature(20, 150);
        $interval = $point->getInterval();
        $this->assertInstanceOf(CarbonInterval::class, $interval);
        $this->assertSame(150.0, $interval->totalMinutes);
    }

    public function testJsonSerializeKeepsIntMinutes(): void
    {
        $point = new TimeAtTemperature(20, 10);
        $this->assertSame(
            ['temperature_at' => 20, 'time_in_minutes' => 10, 'is_calculated' => false],
            $point->jsonSerialize(),
        );
    }
}
