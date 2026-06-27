<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Shared\Infrastructure\Exception\AppException;
use Carbon\CarbonInterval;
use PHPUnit\Framework\TestCase;

final class TimeAtTemperatureTest extends TestCase
{
    public function testDurationPoint(): void
    {
        $point = new TimeAtTemperature(20, 10);
        $this->assertSame(20, $point->temperatureAt);
        $this->assertSame(10, $point->timeInMinutes);
        $this->assertFalse($point->isCalculated);
    }

    public function testUnlimitedPointIsZeroMinutes(): void
    {
        $point = new TimeAtTemperature(20, 0);
        $this->assertSame(0, $point->timeInMinutes);
    }

    public function testUnknownPointIsNullMinutes(): void
    {
        $point = new TimeAtTemperature(20, null);
        $this->assertNull($point->timeInMinutes);
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

    public function testGetIntervalForDurationReturnsCarbonInterval(): void
    {
        $point = new TimeAtTemperature(20, 150);
        $interval = $point->getInterval();
        $this->assertInstanceOf(CarbonInterval::class, $interval);
        $this->assertSame(150.0, $interval->totalMinutes);
    }

    public function testGetIntervalForUnlimitedReturnsNull(): void
    {
        $point = new TimeAtTemperature(20, 0);
        $this->assertNull($point->getInterval());
    }

    public function testGetIntervalForUnknownReturnsNull(): void
    {
        $point = new TimeAtTemperature(20, null);
        $this->assertNull($point->getInterval());
    }

    public function testJsonSerializeKeepsDurationMinutes(): void
    {
        $point = new TimeAtTemperature(20, 10);
        $this->assertSame(
            ['temperature_at' => 20, 'time_in_minutes' => 10, 'is_calculated' => false],
            $point->jsonSerialize(),
        );
    }

    public function testJsonSerializeKeepsUnlimitedAsZero(): void
    {
        $point = new TimeAtTemperature(20, 0);
        $this->assertSame(
            ['temperature_at' => 20, 'time_in_minutes' => 0, 'is_calculated' => false],
            $point->jsonSerialize(),
        );
    }

    public function testJsonSerializeKeepsUnknownAsNull(): void
    {
        $point = new TimeAtTemperature(20, null);
        $this->assertSame(
            ['temperature_at' => 20, 'time_in_minutes' => null, 'is_calculated' => false],
            $point->jsonSerialize(),
        );
    }
}
