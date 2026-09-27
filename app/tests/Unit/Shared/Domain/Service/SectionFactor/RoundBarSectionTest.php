<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Service\SectionFactor;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\Service\SectionFactor\HeatingScheme;
use App\Shared\Domain\Service\SectionFactor\RoundBarSection;
use App\Shared\Domain\Service\SectionFactorCalculator;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class RoundBarSectionTest extends TestCase
{
    public function test_area_is_full_circle(): void
    {
        // D=100: πD²/4 = 7853.982
        self::assertEqualsWithDelta(7853.982, (new RoundBarSection(100))->crossSectionArea(), 0.01);
    }

    public function test_perimeter_is_circumference(): void
    {
        self::assertEqualsWithDelta(314.159, (new RoundBarSection(100))->heatedPerimeter(HeatingScheme::allSides()), 0.01);
    }

    public function test_reduced_thickness_of_solid_bar_is_quarter_of_diameter(): void
    {
        // сплошной круг: ПТМ = D/4 = 25
        $bar = new RoundBarSection(100);
        $ptm = (new SectionFactorCalculator())->reducedThickness(
            new PositiveNumber($bar->crossSectionArea()),
            new PositiveNumber($bar->heatedPerimeter(HeatingScheme::allSides())),
        );
        self::assertEqualsWithDelta(25.0, $ptm, 0.001);
    }

    public function test_rejects_non_positive_diameter(): void
    {
        $this->expectException(AppException::class);
        new RoundBarSection(0);
    }
}
