<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Service;

use App\Coatings\Domain\Service\DewPointCalculator;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class DewPointCalculatorTest extends TestCase
{
    private DewPointCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new DewPointCalculator();
    }

    public function test_min_surface_temperature_uses_default_margin(): void
    {
        self::assertSame(13.0, $this->calc->minSurfaceTemperature(10.0));
    }

    public function test_min_surface_temperature_custom_margin(): void
    {
        self::assertSame(15.0, $this->calc->minSurfaceTemperature(10.0, 5.0));
    }

    public function test_surface_above_dew_point_plus_margin_is_acceptable(): void
    {
        self::assertTrue($this->calc->isSurfaceAcceptable(14.0, 10.0));
    }

    public function test_surface_below_dew_point_plus_margin_is_not_acceptable(): void
    {
        self::assertFalse($this->calc->isSurfaceAcceptable(12.0, 10.0));
    }

    public function test_surface_exactly_at_minimum_is_acceptable(): void
    {
        // граница: 13.0 == 10.0 + 3.0 → допустимо
        self::assertTrue($this->calc->isSurfaceAcceptable(13.0, 10.0));
    }

    public function test_dew_point_from_air_temp_and_humidity(): void
    {
        // 20 °C, 50 % → ≈ 9.26 °C (Магнус, Alduchov–Eskridge)
        self::assertEqualsWithDelta(9.26, $this->calc->dewPoint(20.0, new Percent(50)), 0.05);
    }

    public function test_dew_point_equals_air_temp_at_full_humidity(): void
    {
        // 100 % влажности → точка росы = температуре воздуха
        self::assertEqualsWithDelta(20.0, $this->calc->dewPoint(20.0, new Percent(100)), 0.05);
    }

    public function test_dew_point_rejects_zero_humidity(): void
    {
        $this->expectException(AppException::class);
        $this->calc->dewPoint(20.0, new Percent(0));
    }
}
