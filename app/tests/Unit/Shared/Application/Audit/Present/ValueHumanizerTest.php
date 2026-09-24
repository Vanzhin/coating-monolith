<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Audit\Present;

use App\Shared\Application\Audit\Present\AuditValueFormatter;
use App\Shared\Application\Audit\Present\DurationHumanizer;
use App\Shared\Application\Audit\Present\Formatter\DftRangeFormatter;
use App\Shared\Application\Audit\Present\Formatter\DurationSeriesFormatter;
use App\Shared\Application\Audit\Present\Formatter\JsonFallbackFormatter;
use App\Shared\Application\Audit\Present\Formatter\MixingRatioFormatter;
use App\Shared\Application\Audit\Present\Formatter\RecoatingTreeFormatter;
use App\Shared\Application\Audit\Present\Formatter\ScalarFormatter;
use App\Shared\Application\Audit\Present\Formatter\ThermalLimitsFormatter;
use App\Shared\Application\Audit\Present\ValueHumanizer;
use PHPUnit\Framework\TestCase;

final class ValueHumanizerTest extends TestCase
{
    /** Собирает реальную цепочку в том же порядке, что и DI-конфиг (services.yaml). */
    private function fullChain(): ValueHumanizer
    {
        $duration = new DurationHumanizer();
        $series = new DurationSeriesFormatter($duration);

        return new ValueHumanizer(
            new DftRangeFormatter(),
            $series,
            new RecoatingTreeFormatter($series),
            new ThermalLimitsFormatter($duration),
            new MixingRatioFormatter(),
            new ScalarFormatter(),
            new JsonFallbackFormatter(),
        );
    }

    public function test_bool_routes_to_scalar(): void
    {
        self::assertSame('Да', $this->fullChain()->humanize(true));
    }

    public function test_null_routes_to_scalar(): void
    {
        self::assertSame('—', $this->fullChain()->humanize(null));
    }

    public function test_string_routes_to_scalar(): void
    {
        self::assertSame('foo', $this->fullChain()->humanize('foo'));
    }

    public function test_number_routes_to_scalar(): void
    {
        self::assertSame('5', $this->fullChain()->humanize(5));
    }

    public function test_unknown_shape_falls_back_to_pretty_json_with_cyrillic(): void
    {
        $result = $this->fullChain()->humanize(['foo' => 'бар']);

        self::assertStringContainsString('бар', $result);
        self::assertStringNotContainsString('\\u', $result);
    }

    public function test_dft_range_shape_routes_to_dft_formatter(): void
    {
        $value = ['min' => 50, 'max' => 100, 'tds_dft' => 75, 'type' => 'мкм'];

        self::assertSame('50–100 мкм (целевая 75)', $this->fullChain()->humanize($value));
    }

    public function test_mixing_ratio_shape_routes_to_mixing_formatter(): void
    {
        $value = ['volume' => [4, 1], 'mass' => null];

        self::assertSame('по объёму 4:1', $this->fullChain()->humanize($value));
    }

    public function test_thermal_limits_shape_routes_to_thermal_formatter(): void
    {
        self::assertSame('непрерывно до +120 °C', $this->fullChain()->humanize(['continuous_max' => 120]));
    }

    public function test_recoating_tree_shape_routes_to_tree_formatter(): void
    {
        $tree = [
            'default' => [
                ['temperature_at' => 5, 'time_in_minutes' => 960, 'is_calculated' => false],
            ],
            'children' => [],
        ];

        self::assertSame('5 °C — 16 ч', $this->fullChain()->humanize($tree));
    }

    public function test_series_list_shape_routes_to_series_formatter(): void
    {
        $points = [
            ['temperature_at' => 5, 'time_in_minutes' => 960, 'is_calculated' => false],
        ];

        self::assertSame('5 °C — 16 ч', $this->fullChain()->humanize($points));
    }

    public function test_formatter_exception_is_caught_and_never_propagates(): void
    {
        $throwing = new class implements AuditValueFormatter {
            public function supports(mixed $value): bool
            {
                return true;
            }

            public function format(mixed $value): string
            {
                throw new \RuntimeException('boom');
            }
        };

        // Единственный элемент цепочки — сама ValueHumanizer гарантирует fallback,
        // не полагаясь на то, что вызывающий код добавил JsonFallbackFormatter.
        $result = (new ValueHumanizer($throwing))->humanize(['foo' => 'бар']);

        self::assertStringContainsString('бар', $result);
    }
}
