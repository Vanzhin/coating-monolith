<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\ThermalExposureLimits;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class ThermalExposureLimitsTest extends TestCase
{
    public function test_accepts_valid_continuous_range(): void
    {
        $limits = new ThermalExposureLimits(-30, 100);

        self::assertSame(-30, $limits->continuousMin);
        self::assertSame(100, $limits->continuousMax);
        self::assertNull($limits->peakMax);
        self::assertNull($limits->peakDurationMinutes);
    }

    public function test_peak_without_duration_defaults_to60(): void
    {
        $limits = new ThermalExposureLimits(-30, 100, 120);

        self::assertSame(120, $limits->peakMax);
        self::assertSame(60, $limits->peakDurationMinutes);
    }

    public function test_accepts_peak_above_continuous_max(): void
    {
        $limits = new ThermalExposureLimits(-30, 100, 120, 90);

        self::assertSame(120, $limits->peakMax);
        self::assertSame(90, $limits->peakDurationMinutes);
    }

    public function test_rejects_continuous_min_greater_or_equal_max(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/строго меньше/');
        new ThermalExposureLimits(50, 50);
    }

    public function test_accepts_peak_equal_to_continuous_max(): void
    {
        // Равенство допустимо: некоторые PDS'ы дают одно и то же значение
        // для непрерывной и пиковой максимальной.
        $limits = new ThermalExposureLimits(0, 100, 100);
        self::assertSame(100, $limits->peakMax);
        self::assertSame(100, $limits->continuousMax);
    }

    public function test_rejects_peak_below_continuous_max(): void
    {
        $this->expectException(AppException::class);
        new ThermalExposureLimits(0, 100, 80);
    }

    public function test_rejects_peak_duration_without_peak_max(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/без самой пиковой/');
        new ThermalExposureLimits(0, 100, null, 60);
    }

    public function test_rejects_zero_peak_duration(): void
    {
        $this->expectException(AppException::class);
        new ThermalExposureLimits(0, 100, 120, 0);
    }

    public function test_json_roundtrip(): void
    {
        $original = new ThermalExposureLimits(-30, 100, 120, 60);
        $restored = ThermalExposureLimits::fromArray($original->jsonSerialize());

        self::assertEquals($original, $restored);
    }

    public function test_json_roundtrip_without_peak(): void
    {
        $original = new ThermalExposureLimits(-30, 50);
        $restored = ThermalExposureLimits::fromArray($original->jsonSerialize());

        self::assertEquals($original, $restored);
    }

    public function test_covers_inside_continuous_range(): void
    {
        $limits = new ThermalExposureLimits(-30, 100);

        self::assertTrue($limits->covers(-30, false));
        self::assertTrue($limits->covers(50, false));
        self::assertTrue($limits->covers(100, false));
    }

    public function test_covers_rejects_outside_continuous_range(): void
    {
        $limits = new ThermalExposureLimits(-30, 100);

        self::assertFalse($limits->covers(-31, false));
        self::assertFalse($limits->covers(101, false));
    }

    public function test_covers_with_peak_expands_upper_bound(): void
    {
        $limits = new ThermalExposureLimits(-30, 100, 140);

        self::assertFalse($limits->covers(120, false));
        self::assertTrue($limits->covers(120, true));
        self::assertTrue($limits->covers(140, true));
        self::assertFalse($limits->covers(141, true));
    }

    public function test_covers_with_peak_does_not_affect_lower_bound(): void
    {
        $limits = new ThermalExposureLimits(-30, 100, 140);

        self::assertFalse($limits->covers(-31, true));
    }

    public function test_covers_with_peak_falls_back_to_continuous_when_no_peak_set(): void
    {
        $limits = new ThermalExposureLimits(-30, 100);

        self::assertTrue($limits->covers(100, true));
        self::assertFalse($limits->covers(101, true));
    }

    public function test_accepts_only_continuous_max(): void
    {
        $limits = new ThermalExposureLimits(continuousMax: 120);

        self::assertNull($limits->continuousMin);
        self::assertSame(120, $limits->continuousMax);
    }

    public function test_accepts_only_peak(): void
    {
        $limits = new ThermalExposureLimits(peakMax: 140);

        self::assertNull($limits->continuousMin);
        self::assertNull($limits->continuousMax);
        self::assertSame(140, $limits->peakMax);
        self::assertSame(60, $limits->peakDurationMinutes);
    }

    public function test_rejects_all_nulls(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/хотя бы одно/');
        new ThermalExposureLimits();
    }

    public function test_covers_with_null_lower_bound_is_unrestricted_below(): void
    {
        $limits = new ThermalExposureLimits(continuousMax: 120);

        self::assertTrue($limits->covers(-100, false));
        self::assertTrue($limits->covers(120, false));
        self::assertFalse($limits->covers(121, false));
    }

    public function test_covers_with_null_upper_bound_is_unrestricted_above(): void
    {
        $limits = new ThermalExposureLimits(continuousMin: 0);

        self::assertFalse($limits->covers(-1, false));
        self::assertTrue($limits->covers(0, false));
        self::assertTrue($limits->covers(500, false));
    }

    public function test_covers_with_only_peak_defined_requires_including_peak_flag(): void
    {
        $limits = new ThermalExposureLimits(peakMax: 140);

        // includingPeak=false → верхняя граница = continuous_max (null) → без ограничения
        self::assertTrue($limits->covers(200, false));
        // includingPeak=true → верхняя граница = peak_max = 140
        self::assertTrue($limits->covers(140, true));
        self::assertFalse($limits->covers(141, true));
    }
}
