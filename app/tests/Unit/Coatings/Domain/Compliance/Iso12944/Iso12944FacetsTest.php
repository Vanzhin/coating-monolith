<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Compliance\Iso12944;

use App\Coatings\Domain\Compliance\Iso12944\Iso12944Facets;
use PHPUnit\Framework\TestCase;

final class Iso12944FacetsTest extends TestCase
{
    public function test_atmospheric_and_immersion_categories_have_durability_axis(): void
    {
        $facets = new Iso12944Facets();

        self::assertTrue($facets->hasSecondaryAxis('C3'));
        self::assertTrue($facets->hasSecondaryAxis('C5'));
        self::assertTrue($facets->hasSecondaryAxis('Im3'));
    }

    public function test_cx_and_im4_have_no_durability_axis(): void
    {
        $facets = new Iso12944Facets();

        // ГОСТ 34667.9: у CX и Im4 долговечность всегда высокая — второй оси нет.
        self::assertFalse($facets->hasSecondaryAxis('CX'));
        self::assertFalse($facets->hasSecondaryAxis('Im4'));
    }

    public function test_badge_label_marks_cx_and_im4_as_high(): void
    {
        $facets = new Iso12944Facets();

        self::assertSame('CX-H', $facets->badgeLabel('CX', 'HIGH'));
        self::assertSame('Im4-H', $facets->badgeLabel('Im4', 'HIGH'));
    }
}
