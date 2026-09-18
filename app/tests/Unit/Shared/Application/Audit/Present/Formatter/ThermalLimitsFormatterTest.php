<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Audit\Present\Formatter;

use App\Shared\Application\Audit\Present\DurationHumanizer;
use App\Shared\Application\Audit\Present\Formatter\ThermalLimitsFormatter;
use PHPUnit\Framework\TestCase;

final class ThermalLimitsFormatterTest extends TestCase
{
    private function formatter(): ThermalLimitsFormatter
    {
        return new ThermalLimitsFormatter(new DurationHumanizer());
    }

    public function test_supports_partial_thermal_shape(): void
    {
        self::assertTrue($this->formatter()->supports(['continuous_max' => 120]));
    }

    public function test_supports_full_thermal_shape(): void
    {
        self::assertTrue($this->formatter()->supports([
            'continuous_min' => -30,
            'continuous_max' => 120,
            'peak_max' => 140,
            'peak_duration_minutes' => 90,
        ]));
    }

    public function test_does_not_support_dft_range_shape(): void
    {
        self::assertFalse($this->formatter()->supports(['min' => 50, 'max' => 100, 'tds_dft' => 75, 'type' => 'мкм']));
    }

    public function test_does_not_support_mixing_ratio_shape(): void
    {
        self::assertFalse($this->formatter()->supports(['volume' => [4, 1], 'mass' => null]));
    }

    public function test_does_not_support_empty_array(): void
    {
        self::assertFalse($this->formatter()->supports([]));
    }

    public function test_full_example_with_range_and_peak_duration(): void
    {
        $result = $this->formatter()->format([
            'continuous_min' => -30,
            'continuous_max' => 120,
            'peak_max' => 140,
            'peak_duration_minutes' => 90,
        ]);

        self::assertSame('непрерывно −30…+120 °C; пик +140 °C до 1 ч 30 мин', $result);
    }

    public function test_only_max_continuous_limit(): void
    {
        self::assertSame('непрерывно до +120 °C', $this->formatter()->format(['continuous_max' => 120]));
    }

    public function test_only_min_continuous_limit(): void
    {
        self::assertSame('непрерывно от −30 °C', $this->formatter()->format(['continuous_min' => -30]));
    }

    public function test_peak_without_duration(): void
    {
        self::assertSame('пик +140 °C', $this->formatter()->format(['peak_max' => 140]));
    }

    public function test_empty_parts_are_omitted(): void
    {
        self::assertSame('', $this->formatter()->format([
            'continuous_min' => null,
            'continuous_max' => null,
            'peak_max' => null,
            'peak_duration_minutes' => null,
        ]));
    }
}
