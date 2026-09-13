<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Service;

use App\Coatings\Domain\Service\FilmThicknessCalculator;
use App\Coatings\Domain\Service\PaintConsumptionCalculator;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class PaintConsumptionCalculatorTest extends TestCase
{
    private PaintConsumptionCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new PaintConsumptionCalculator(new FilmThicknessCalculator());
    }

    public function test_liters_per_square_meter_without_loss(): void
    {
        // DFT 100 мкм, VS 50% → 100/(50×10) = 0.2 л/м².
        self::assertEqualsWithDelta(0.2, $this->calc->litersPerSquareMeter(new Percent(50), new PositiveNumber(100), new Percent(0)), 0.0001);
    }

    public function test_liters_per_square_meter_with_loss(): void
    {
        // Потери 30% → коэффициент 1/0.7 ≈ 1.4286 → 0.2 × 1.4286 ≈ 0.2857 л/м².
        self::assertEqualsWithDelta(0.2857, $this->calc->litersPerSquareMeter(new Percent(50), new PositiveNumber(100), new Percent(30)), 0.0005);
    }

    public function test_loss_coefficient(): void
    {
        self::assertSame(1.0, $this->calc->lossCoefficient(new Percent(0)));
        self::assertEqualsWithDelta(1.4286, $this->calc->lossCoefficient(new Percent(30)), 0.0005);
    }

    public function test_rejects_full_loss(): void
    {
        $this->expectException(AppException::class);
        $this->calc->lossCoefficient(new Percent(100));
    }

    public function test_total_liters_scales_by_area(): void
    {
        self::assertEqualsWithDelta(10.0, $this->calc->totalLiters(new PositiveNumber(0.2), new PositiveNumber(50)), 0.0001);
    }

    public function test_coverage_is_inverse_of_rate(): void
    {
        self::assertEqualsWithDelta(100.0, $this->calc->coverageSquareMeters(new PositiveNumber(0.2), new PositiveNumber(20)), 0.0001);
    }

    public function test_rejects_zero_volume_solids(): void
    {
        $this->expectException(AppException::class);
        $this->calc->litersPerSquareMeter(new Percent(0), new PositiveNumber(100), new Percent(0));
    }

    public function test_packs_needed_rounds_up(): void
    {
        // 35 л при фасовке 20 л → 2 ведра (неполное докупаем).
        self::assertSame(2, $this->calc->packsNeeded(new PositiveNumber(35), new PositiveNumber(20)));
        self::assertSame(2, $this->calc->packsNeeded(new PositiveNumber(40), new PositiveNumber(20)));
        self::assertSame(3, $this->calc->packsNeeded(new PositiveNumber(41), new PositiveNumber(20)));
    }

    public function test_mass_from_volume(): void
    {
        // 10 л × 1.5 кг/л = 15 кг.
        self::assertEqualsWithDelta(15.0, $this->calc->massKilograms(new PositiveNumber(10), new PositiveNumber(1.5)), 0.0001);
    }
}
