<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Repository;

use App\Coatings\Domain\Repository\CoatingSystemSort;
use PHPUnit\Framework\TestCase;

final class CoatingSystemSortTest extends TestCase
{
    public function test_all_cases_have_values(): void
    {
        $values = array_map(static fn (CoatingSystemSort $s) => $s->value, CoatingSystemSort::cases());
        sort($values);
        self::assertSame(
            ['default', 'min_application_time_asc', 'min_application_time_desc', 'title_asc', 'title_desc'],
            $values,
        );
    }

    public function test_titles_are_russian_non_empty(): void
    {
        foreach (CoatingSystemSort::cases() as $sort) {
            self::assertNotEmpty($sort->title());
        }
    }
}
