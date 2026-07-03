<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\ThermalExposureLimits;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class ThermalExposureLimitsTest extends TestCase
{
    public function testAcceptsValidContinuousRange(): void
    {
        $limits = new ThermalExposureLimits(-30, 100);

        self::assertSame(-30, $limits->continuousMin);
        self::assertSame(100, $limits->continuousMax);
        self::assertNull($limits->peakMax);
        self::assertNull($limits->peakDurationMinutes);
    }

    public function testPeakWithoutDurationDefaultsTo60(): void
    {
        $limits = new ThermalExposureLimits(-30, 100, 120);

        self::assertSame(120, $limits->peakMax);
        self::assertSame(60, $limits->peakDurationMinutes);
    }

    public function testAcceptsPeakAboveContinuousMax(): void
    {
        $limits = new ThermalExposureLimits(-30, 100, 120, 90);

        self::assertSame(120, $limits->peakMax);
        self::assertSame(90, $limits->peakDurationMinutes);
    }

    public function testRejectsContinuousMinGreaterOrEqualMax(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/строго меньше/');
        new ThermalExposureLimits(50, 50);
    }

    public function testRejectsPeakEqualToContinuousMax(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/строго выше/');
        new ThermalExposureLimits(0, 100, 100);
    }

    public function testRejectsPeakBelowContinuousMax(): void
    {
        $this->expectException(AppException::class);
        new ThermalExposureLimits(0, 100, 80);
    }

    public function testRejectsPeakDurationWithoutPeakMax(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/без самой пиковой/');
        new ThermalExposureLimits(0, 100, null, 60);
    }

    public function testRejectsZeroPeakDuration(): void
    {
        $this->expectException(AppException::class);
        new ThermalExposureLimits(0, 100, 120, 0);
    }

    public function testJsonRoundtrip(): void
    {
        $original = new ThermalExposureLimits(-30, 100, 120, 60);
        $restored = ThermalExposureLimits::fromArray($original->jsonSerialize());

        self::assertEquals($original, $restored);
    }

    public function testJsonRoundtripWithoutPeak(): void
    {
        $original = new ThermalExposureLimits(-30, 50);
        $restored = ThermalExposureLimits::fromArray($original->jsonSerialize());

        self::assertEquals($original, $restored);
    }

    public function testCoversInsideContinuousRange(): void
    {
        $limits = new ThermalExposureLimits(-30, 100);

        self::assertTrue($limits->covers(-30, false));
        self::assertTrue($limits->covers(50, false));
        self::assertTrue($limits->covers(100, false));
    }

    public function testCoversRejectsOutsideContinuousRange(): void
    {
        $limits = new ThermalExposureLimits(-30, 100);

        self::assertFalse($limits->covers(-31, false));
        self::assertFalse($limits->covers(101, false));
    }

    public function testCoversWithPeakExpandsUpperBound(): void
    {
        $limits = new ThermalExposureLimits(-30, 100, 140);

        self::assertFalse($limits->covers(120, false));
        self::assertTrue($limits->covers(120, true));
        self::assertTrue($limits->covers(140, true));
        self::assertFalse($limits->covers(141, true));
    }

    public function testCoversWithPeakDoesNotAffectLowerBound(): void
    {
        $limits = new ThermalExposureLimits(-30, 100, 140);

        self::assertFalse($limits->covers(-31, true));
    }

    public function testCoversWithPeakFallsBackToContinuousWhenNoPeakSet(): void
    {
        $limits = new ThermalExposureLimits(-30, 100);

        self::assertTrue($limits->covers(100, true));
        self::assertFalse($limits->covers(101, true));
    }

    public function testAcceptsOnlyContinuousMax(): void
    {
        $limits = new ThermalExposureLimits(continuousMax: 120);

        self::assertNull($limits->continuousMin);
        self::assertSame(120, $limits->continuousMax);
    }

    public function testAcceptsOnlyPeak(): void
    {
        $limits = new ThermalExposureLimits(peakMax: 140);

        self::assertNull($limits->continuousMin);
        self::assertNull($limits->continuousMax);
        self::assertSame(140, $limits->peakMax);
        self::assertSame(60, $limits->peakDurationMinutes);
    }

    public function testRejectsAllNulls(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/хотя бы одно/');
        new ThermalExposureLimits();
    }

    public function testCoversWithNullLowerBoundIsUnrestrictedBelow(): void
    {
        $limits = new ThermalExposureLimits(continuousMax: 120);

        self::assertTrue($limits->covers(-100, false));
        self::assertTrue($limits->covers(120, false));
        self::assertFalse($limits->covers(121, false));
    }

    public function testCoversWithNullUpperBoundIsUnrestrictedAbove(): void
    {
        $limits = new ThermalExposureLimits(continuousMin: 0);

        self::assertFalse($limits->covers(-1, false));
        self::assertTrue($limits->covers(0, false));
        self::assertTrue($limits->covers(500, false));
    }

    public function testCoversWithOnlyPeakDefinedRequiresIncludingPeakFlag(): void
    {
        $limits = new ThermalExposureLimits(peakMax: 140);

        // includingPeak=false → верхняя граница = continuous_max (null) → без ограничения
        self::assertTrue($limits->covers(200, false));
        // includingPeak=true → верхняя граница = peak_max = 140
        self::assertTrue($limits->covers(140, true));
        self::assertFalse($limits->covers(141, true));
    }
}
