<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Audit\Present;

use App\Shared\Application\Audit\Present\DurationHumanizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationHumanizerTest extends TestCase
{
    public function test_null_means_no_data(): void
    {
        self::assertSame('нет данных', (new DurationHumanizer())->format(null));
    }

    public function test_zero_means_unlimited(): void
    {
        self::assertSame('без ограничения', (new DurationHumanizer())->format(0));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function positiveCases(): iterable
    {
        yield 'hours only' => [960, '16 ч'];
        yield 'hours and minutes' => [505, '8 ч 25 мин'];
        yield 'many days' => [30240, '21 сут'];
        yield 'exactly one day' => [1440, '1 сут'];
        yield 'nine hours' => [540, '9 ч'];
        yield 'minutes only' => [45, '45 мин'];
        yield 'day and minutes, zero hours dropped' => [1470, '1 сут 30 мин'];
        yield 'day and hour, top two only, minutes dropped' => [1500, '1 сут 1 ч'];
        yield 'three nonzero units, real truncation not just zero-filtering' => [1525, '1 сут 1 ч'];
    }

    #[DataProvider('positiveCases')]
    public function test_decomposes_into_top_two_units(int $minutes, string $expected): void
    {
        self::assertSame($expected, (new DurationHumanizer())->format($minutes));
    }

    public function test_negative_value_goes_through_same_decomposition_not_a_special_case(): void
    {
        self::assertSame('-8 ч 25 мин', (new DurationHumanizer())->format(-505));
    }
}
