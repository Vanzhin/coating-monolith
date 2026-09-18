<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Audit\Present\Formatter;

use App\Shared\Application\Audit\Present\DurationHumanizer;
use App\Shared\Application\Audit\Present\Formatter\DurationSeriesFormatter;
use PHPUnit\Framework\TestCase;

final class DurationSeriesFormatterTest extends TestCase
{
    private function formatter(): DurationSeriesFormatter
    {
        return new DurationSeriesFormatter(new DurationHumanizer());
    }

    public function test_supports_non_empty_list_of_points(): void
    {
        $points = [
            ['temperature_at' => 5, 'time_in_minutes' => 960, 'is_calculated' => false],
        ];

        self::assertTrue($this->formatter()->supports($points));
    }

    public function test_does_not_support_empty_list(): void
    {
        self::assertFalse($this->formatter()->supports([]));
    }

    public function test_does_not_support_dft_range_shape(): void
    {
        self::assertFalse($this->formatter()->supports(['min' => 50, 'max' => 100, 'tds_dft' => 75, 'type' => 'мкм']));
    }

    public function test_does_not_support_recoating_tree_shape(): void
    {
        self::assertFalse($this->formatter()->supports(['default' => [], 'children' => []]));
    }

    public function test_does_not_support_list_of_wrong_shape(): void
    {
        self::assertFalse($this->formatter()->supports([['foo' => 1]]));
    }

    public function test_formats_only_non_calculated_points_joined_with_semicolon(): void
    {
        $points = [
            ['temperature_at' => 5, 'time_in_minutes' => 960, 'is_calculated' => false],
            ['temperature_at' => 10, 'time_in_minutes' => 360, 'is_calculated' => false],
            ['temperature_at' => 23, 'time_in_minutes' => 210, 'is_calculated' => false],
        ];

        self::assertSame('5 °C — 16 ч; 10 °C — 6 ч; 23 °C — 3 ч 30 мин', $this->formatter()->format($points));
    }

    public function test_calculated_points_are_hidden(): void
    {
        $points = [
            ['temperature_at' => 5, 'time_in_minutes' => 960, 'is_calculated' => false],
            ['temperature_at' => 10, 'time_in_minutes' => 480, 'is_calculated' => true],
        ];

        self::assertSame('5 °C — 16 ч', $this->formatter()->format($points));
    }

    public function test_null_time_point_is_hidden(): void
    {
        $points = [
            ['temperature_at' => 5, 'time_in_minutes' => null, 'is_calculated' => false],
            ['temperature_at' => 10, 'time_in_minutes' => 360, 'is_calculated' => false],
        ];

        self::assertSame('10 °C — 6 ч', $this->formatter()->format($points));
    }

    public function test_zero_time_point_is_shown_as_unlimited(): void
    {
        $points = [
            ['temperature_at' => 5, 'time_in_minutes' => 0, 'is_calculated' => false],
        ];

        self::assertSame('5 °C — без ограничения', $this->formatter()->format($points));
    }
}
