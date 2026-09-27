<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Service;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\Service\SectionFactorCalculator;
use PHPUnit\Framework\TestCase;

final class SectionFactorCalculatorTest extends TestCase
{
    private SectionFactorCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new SectionFactorCalculator();
    }

    public function test_reduced_thickness_is_area_over_perimeter(): void
    {
        // ПТМ = площадь сечения / обогреваемый периметр
        self::assertSame(2.5, $this->calc->reducedThickness(new PositiveNumber(2000), new PositiveNumber(800)));
    }

    public function test_surface_area_per_meter_converts_mm_to_m2(): void
    {
        // периметр (мм) на 1 м длины → м²: perimeter / 1000
        self::assertSame(1.25, $this->calc->surfaceAreaPerMeter(new PositiveNumber(1250)));
    }

    public function test_mass_per_meter_uses_steel_density(): void
    {
        // масса 1 м = площадь(мм²)/1000 × 7.85 (плотность стали)
        self::assertEqualsWithDelta(7.85, $this->calc->massPerMeter(new PositiveNumber(1000)), 0.0001);
    }

    public function test_surface_area_per_ton(): void
    {
        // м²/т = (1000 кг / масса 1 м) × площадь пов-ти 1 м
        // масса 1 м = 1000/1000×7.85 = 7.85 кг; 1000/7.85 × 1.0 = 127.389
        self::assertEqualsWithDelta(127.389, $this->calc->surfaceAreaPerTon(new PositiveNumber(1000), 1.0), 0.01);
    }

    public function test_reduced_thickness_matches_known_profile_20b1(): void
    {
        // двутавр 20Б1, 4-стороннее: площадь 2849 мм² (сортамент, с R), контурный периметр 789 мм → ПТМ ≈ 3.61
        self::assertEqualsWithDelta(3.61, $this->calc->reducedThickness(new PositiveNumber(2849), new PositiveNumber(789)), 0.01);
    }
}
