<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Audit\Present\Formatter;

use App\Shared\Application\Audit\Present\Formatter\MixingRatioFormatter;
use PHPUnit\Framework\TestCase;

final class MixingRatioFormatterTest extends TestCase
{
    public function test_supports_volume_and_mass_shape(): void
    {
        self::assertTrue((new MixingRatioFormatter())->supports(['volume' => [4, 1], 'mass' => null]));
    }

    public function test_supports_partial_shape_with_only_volume(): void
    {
        self::assertTrue((new MixingRatioFormatter())->supports(['volume' => [4, 1]]));
    }

    public function test_does_not_support_thermal_limits_shape(): void
    {
        self::assertFalse((new MixingRatioFormatter())->supports(['continuous_max' => 120]));
    }

    public function test_does_not_support_dft_range_shape(): void
    {
        self::assertFalse((new MixingRatioFormatter())->supports(['min' => 50, 'max' => 100, 'tds_dft' => 75, 'type' => 'мкм']));
    }

    public function test_does_not_support_empty_array(): void
    {
        self::assertFalse((new MixingRatioFormatter())->supports([]));
    }

    public function test_formats_volume_ratio_only(): void
    {
        self::assertSame('по объёму 4:1', (new MixingRatioFormatter())->format(['volume' => [4, 1], 'mass' => null]));
    }

    public function test_formats_both_ratios_joined_with_semicolon(): void
    {
        $result = (new MixingRatioFormatter())->format(['volume' => [4, 1], 'mass' => [3, 1]]);

        self::assertSame('по объёму 4:1; по массе 3:1', $result);
    }

    public function test_null_ratios_are_omitted(): void
    {
        self::assertSame('', (new MixingRatioFormatter())->format(['volume' => null, 'mass' => null]));
    }
}
