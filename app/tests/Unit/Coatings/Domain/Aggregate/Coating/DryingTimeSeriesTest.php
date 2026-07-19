<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

class DryingTimeSeriesTest extends TestCase
{
    public function test_empty_series_throws(): void
    {
        $this->expectException(AppException::class);
        new DryingTimeSeries();
    }

    public function test_valid_monotonic_profile(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(5, 30),
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(30, 5),
        );

        $this->assertCount(3, $series->points);
        $temps = array_map(fn (TimeAtTemperature $p) => $p->temperatureAt, $series->points);
        $this->assertSame([5, 20, 30], $temps);
    }

    public function test_sorts_unordered_input(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(30, 5),
            new TimeAtTemperature(5, 30),
            new TimeAtTemperature(20, 10),
        );

        $temps = array_map(fn (TimeAtTemperature $p) => $p->temperatureAt, $series->points);
        $this->assertSame([5, 20, 30], $temps);
    }

    public function test_duplicate_temperature_throws(): void
    {
        $this->expectException(AppException::class);
        new DryingTimeSeries(
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(20, 5),
        );
    }

    public function test_non_monotonic_throws(): void
    {
        $this->expectException(AppException::class);
        new DryingTimeSeries(
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(30, 20),
        );
    }

    public function test_equal_times_allowed(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(25, 10),
        );
        $this->assertCount(2, $series->points);
    }

    public function test_single_point_allowed(): void
    {
        $series = new DryingTimeSeries(new TimeAtTemperature(20, 10));
        $this->assertCount(1, $series->points);
    }

    public function test_get_point_exact(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(30, 5),
        );

        $point = $series->getPoint(20);
        $this->assertNotNull($point);
        $this->assertSame(10, $point->timeInMinutes);
        $this->assertFalse($point->isCalculated);
    }

    public function test_interpolates_between_points(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(30, 5),
        );

        $point = $series->getPoint(25);

        $this->assertNotNull($point);
        // 7.5 округляется до 8 (PHP round half away from zero).
        $this->assertSame(8, $point->timeInMinutes);
        $this->assertTrue($point->isCalculated);
    }

    public function test_get_point_out_of_range_returns_null(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(30, 5),
        );

        $this->assertNull($series->getPoint(10));
        $this->assertNull($series->getPoint(35));
    }

    public function test_json_serialize_uses_snake_case(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 10),
            new TimeAtTemperature(30, 5),
        );

        // jsonSerialize() возвращает list<TimeAtTemperature>; json_encode рекурсивно
        // вызовет TimeAtTemperature::jsonSerialize() и получится snake_case-структура.
        $this->assertSame(
            [
                ['temperature_at' => 20, 'time_in_minutes' => 10, 'is_calculated' => false],
                ['temperature_at' => 30, 'time_in_minutes' => 5, 'is_calculated' => false],
            ],
            json_decode(json_encode($series), true),
        );
    }

    public function test_from_array_round_trips_serialization(): void
    {
        $original = new DryingTimeSeries(
            new TimeAtTemperature(20, 10, isCalculated: true),
            new TimeAtTemperature(30, 5),
        );

        $raw = json_decode(json_encode($original), true);
        $restored = DryingTimeSeries::fromArray($raw);

        $this->assertSame(
            json_decode(json_encode($original), true),
            json_decode(json_encode($restored), true),
        );
    }

    public function test_from_array_preserves_is_calculated_flag(): void
    {
        $raw = [
            ['temperature_at' => 20, 'time_in_minutes' => 10, 'is_calculated' => false],
            ['temperature_at' => 25, 'time_in_minutes' => 8,  'is_calculated' => true],
            ['temperature_at' => 30, 'time_in_minutes' => 5,  'is_calculated' => false],
        ];

        $series = DryingTimeSeries::fromArray($raw);

        $this->assertCount(3, $series->points);
        $this->assertSame(20, $series->points[0]->temperatureAt);
        $this->assertFalse($series->points[0]->isCalculated);
        $this->assertSame(25, $series->points[1]->temperatureAt);
        $this->assertTrue($series->points[1]->isCalculated);
    }

    public function test_mixed_series_with_unlimited_and_unknown_is_valid(): void
    {
        // Серия: 10°C → 24h (duration), 20°C → null (N/A), 30°C → 12h (duration), 40°C → 0 (unlimited).
        // Физ-правило применяется только к Duration: 24h@10 → 12h@30 — ОК.
        $series = new DryingTimeSeries(
            new TimeAtTemperature(10, 24 * 60),
            new TimeAtTemperature(20, null),
            new TimeAtTemperature(30, 12 * 60),
            new TimeAtTemperature(40, 0),
        );
        $this->assertCount(4, $series->points);
    }

    public function test_phys_rule_still_enforced_among_duration_points(): void
    {
        // Среди Duration: 10°C → 60min, 30°C → 120min — нарушение (выросло с температурой).
        $this->expectException(AppException::class);
        new DryingTimeSeries(
            new TimeAtTemperature(10, 60),
            new TimeAtTemperature(20, null),
            new TimeAtTemperature(30, 120),
        );
    }

    public function test_get_point_exact_match_returns_unknown_as_is(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(10, 24 * 60),
            new TimeAtTemperature(20, null),
            new TimeAtTemperature(30, 12 * 60),
        );
        $p = $series->getPoint(20);
        $this->assertNotNull($p);
        $this->assertSame(20, $p->temperatureAt);
        $this->assertNull($p->timeInMinutes);
    }

    public function test_get_point_exact_match_returns_unlimited_as_is(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(10, 24 * 60),
            new TimeAtTemperature(40, 0),
        );
        $p = $series->getPoint(40);
        $this->assertNotNull($p);
        $this->assertSame(40, $p->temperatureAt);
        $this->assertSame(0, $p->timeInMinutes);
    }

    public function test_get_point_interpolates_across_non_duration_point(): void
    {
        // 10°C → 24h = 1440min, 20°C → null, 30°C → 12h = 720min.
        // Запрос 15°C: интерполяция между 10 и 30 (null в 20 пропускается).
        // Линейная: 1440 + (720-1440) * (15-10) / (30-10) = 1440 - 720*5/20 = 1440 - 180 = 1260min.
        $series = new DryingTimeSeries(
            new TimeAtTemperature(10, 1440),
            new TimeAtTemperature(20, null),
            new TimeAtTemperature(30, 720),
        );
        $p = $series->getPoint(15);
        $this->assertNotNull($p);
        $this->assertSame(15, $p->temperatureAt);
        $this->assertSame(1260, $p->timeInMinutes);
        $this->assertTrue($p->isCalculated);
    }

    public function test_get_point_returns_null_when_upper_bound_is_unlimited(): void
    {
        // 10°C → 24h, 40°C → 0 (unlimited). Запрос 30°C → upper Duration нет → null.
        $series = new DryingTimeSeries(
            new TimeAtTemperature(10, 1440),
            new TimeAtTemperature(40, 0),
        );
        $this->assertNull($series->getPoint(30));
    }

    public function test_get_point_returns_null_when_lower_bound_missing(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 1440),
            new TimeAtTemperature(30, 720),
        );
        $this->assertNull($series->getPoint(5));
    }

    public function test_from_array_with_null_minutes(): void
    {
        $series = DryingTimeSeries::fromArray([
            ['temperature_at' => 10, 'time_in_minutes' => 1440],
            ['temperature_at' => 20, 'time_in_minutes' => null],
            ['temperature_at' => 30, 'time_in_minutes' => 0],
        ]);
        $this->assertCount(3, $series->points);
        $this->assertSame(1440, $series->points[0]->timeInMinutes);
        $this->assertNull($series->points[1]->timeInMinutes);
        $this->assertSame(0, $series->points[2]->timeInMinutes);
    }

    public function test_json_serialize_roundtrip_preserves_all_kinds(): void
    {
        $original = new DryingTimeSeries(
            new TimeAtTemperature(10, 1440),
            new TimeAtTemperature(20, null),
            new TimeAtTemperature(30, 0),
        );
        $serialized = json_decode(json_encode($original->jsonSerialize()), true);
        $restored = DryingTimeSeries::fromArray($serialized);

        $this->assertSame(1440, $restored->points[0]->timeInMinutes);
        $this->assertNull($restored->points[1]->timeInMinutes);
        $this->assertSame(0, $restored->points[2]->timeInMinutes);
    }
}
