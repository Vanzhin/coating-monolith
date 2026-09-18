<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Audit\Present\Formatter;

use App\Shared\Application\Audit\Present\DurationHumanizer;
use App\Shared\Application\Audit\Present\Formatter\DurationSeriesFormatter;
use App\Shared\Application\Audit\Present\Formatter\RecoatingTreeFormatter;
use PHPUnit\Framework\TestCase;

final class RecoatingTreeFormatterTest extends TestCase
{
    private function formatter(): RecoatingTreeFormatter
    {
        return new RecoatingTreeFormatter(new DurationSeriesFormatter(new DurationHumanizer()));
    }

    public function test_supports_default_and_children_shape(): void
    {
        self::assertTrue($this->formatter()->supports(['default' => [], 'children' => []]));
    }

    public function test_supports_children_as_object_shape_decoded_to_assoc_array(): void
    {
        self::assertTrue($this->formatter()->supports(['default' => [], 'children' => ['сталь' => []]]));
    }

    public function test_does_not_support_dft_range_shape(): void
    {
        self::assertFalse($this->formatter()->supports(['min' => 50, 'max' => 100, 'tds_dft' => 75, 'type' => 'мкм']));
    }

    public function test_does_not_support_series_list_shape(): void
    {
        self::assertFalse($this->formatter()->supports([
            ['temperature_at' => 5, 'time_in_minutes' => 960, 'is_calculated' => false],
        ]));
    }

    public function test_formats_default_series_when_no_children(): void
    {
        $tree = [
            'default' => [
                ['temperature_at' => 5, 'time_in_minutes' => 960, 'is_calculated' => false],
            ],
            'children' => [],
        ];

        self::assertSame('5 °C — 16 ч', $this->formatter()->format($tree));
    }

    public function test_appends_each_child_default_series_under_its_layer_key(): void
    {
        $tree = [
            'default' => [
                ['temperature_at' => 5, 'time_in_minutes' => 960, 'is_calculated' => false],
            ],
            'children' => [
                'цинк' => [
                    'default' => [
                        ['temperature_at' => 5, 'time_in_minutes' => 480, 'is_calculated' => false],
                    ],
                    'children' => [],
                ],
            ],
        ];

        self::assertSame('5 °C — 16 ч; над слоем «цинк»: 5 °C — 8 ч', $this->formatter()->format($tree));
    }

    public function test_grandchildren_are_not_recursed_into(): void
    {
        $tree = [
            'default' => [
                ['temperature_at' => 5, 'time_in_minutes' => 960, 'is_calculated' => false],
            ],
            'children' => [
                'цинк' => [
                    'default' => [
                        ['temperature_at' => 5, 'time_in_minutes' => 480, 'is_calculated' => false],
                    ],
                    'children' => [
                        'эмаль' => [
                            'default' => [
                                ['temperature_at' => 5, 'time_in_minutes' => 60, 'is_calculated' => false],
                            ],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->formatter()->format($tree);

        self::assertSame('5 °C — 16 ч; над слоем «цинк»: 5 °C — 8 ч', $result);
        self::assertStringNotContainsString('эмаль', $result);
    }
}
