<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Audit\Present\Formatter;

use App\Shared\Application\Audit\Present\Formatter\DftRangeFormatter;
use PHPUnit\Framework\TestCase;

final class DftRangeFormatterTest extends TestCase
{
    public function test_supports_exact_dft_range_shape(): void
    {
        self::assertTrue((new DftRangeFormatter())->supports(['min' => 50, 'max' => 100, 'tds_dft' => 75, 'type' => 'мкм']));
    }

    public function test_does_not_support_mixing_ratio_shape(): void
    {
        self::assertFalse((new DftRangeFormatter())->supports(['volume' => [4, 1], 'mass' => null]));
    }

    public function test_does_not_support_thermal_limits_shape(): void
    {
        self::assertFalse((new DftRangeFormatter())->supports(['continuous_max' => 120]));
    }

    public function test_does_not_support_scalar(): void
    {
        self::assertFalse((new DftRangeFormatter())->supports('x'));
    }

    public function test_formats_range_with_target_thickness(): void
    {
        $result = (new DftRangeFormatter())->format(['min' => 50, 'max' => 100, 'tds_dft' => 75, 'type' => 'мкм']);

        self::assertSame('50–100 мкм (целевая 75)', $result);
    }
}
