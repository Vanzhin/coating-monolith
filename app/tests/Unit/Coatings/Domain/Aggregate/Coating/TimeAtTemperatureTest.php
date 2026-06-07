<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

class TimeAtTemperatureTest extends TestCase
{
    public function testValidConstruction(): void
    {
        $point = new TimeAtTemperature(20, 10.5);

        $this->assertSame(20, $point->temperatureAt);
        $this->assertSame(10.5, $point->timeInMinutes);
        $this->assertFalse($point->isCalculated);
    }

    public function testNegativeTimeThrows(): void
    {
        $this->expectException(AppException::class);
        new TimeAtTemperature(20, -1.0);
    }

    public function testNegativeTemperatureIsAllowed(): void
    {
        $point = new TimeAtTemperature(-10, 60.0);
        $this->assertSame(-10, $point->temperatureAt);
    }

    public function testIsCalculatedFlag(): void
    {
        $point = new TimeAtTemperature(20, 10.0, isCalculated: true);
        $this->assertTrue($point->isCalculated);
    }

    public function testGetKeyReturnsTemperature(): void
    {
        $point = new TimeAtTemperature(20, 10.0);
        $this->assertSame(20, $point->getKey());
    }

    public function testGetValueReturnsTime(): void
    {
        $point = new TimeAtTemperature(20, 10.0);
        $this->assertSame(10.0, $point->getValue());
    }

    public function testJsonSerializeUsesSnakeCase(): void
    {
        $point = new TimeAtTemperature(20, 10.5);

        $this->assertSame(
            [
                'temperature_at' => 20,
                'time_in_minutes' => 10.5,
                'is_calculated' => false,
            ],
            $point->jsonSerialize(),
        );
    }
}
