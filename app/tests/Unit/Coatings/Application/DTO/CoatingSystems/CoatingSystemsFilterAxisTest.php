<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\DTO\CoatingSystems;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterAxis;
use PHPUnit\Framework\TestCase;

final class CoatingSystemsFilterAxisTest extends TestCase
{
    public function test_every_axis_has_non_empty_label(): void
    {
        foreach (CoatingSystemsFilterAxis::cases() as $axis) {
            self::assertNotSame('', $axis->label(), $axis->value.' без подписи');
        }
    }

    public function test_labels_map_covers_all_cases(): void
    {
        $labels = CoatingSystemsFilterAxis::labels();

        self::assertCount(count(CoatingSystemsFilterAxis::cases()), $labels);
        self::assertSame('Подложка', $labels['substrates']);
        self::assertSame('Вещества', $labels['substanceIds']);
    }

    public function test_grouped_axes_share_label(): void
    {
        // тепловые ключи и диапазоны делят подпись → дашборд схлопнет их в одну строку
        self::assertSame(
            CoatingSystemsFilterAxis::ThermalTemperature->label(),
            CoatingSystemsFilterAxis::ThermalIncludingPeak->label(),
        );
        self::assertSame(
            CoatingSystemsFilterAxis::ApplicationMinTempFrom->label(),
            CoatingSystemsFilterAxis::ApplicationMinTempTo->label(),
        );
    }

    public function test_label_for_unknown_key_is_null(): void
    {
        self::assertNull(CoatingSystemsFilterAxis::labelFor('page'));
    }
}
