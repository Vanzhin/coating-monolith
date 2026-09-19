<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports\Domain\Hint;

use App\Coatings\Domain\Service\DewPointCalculator;
use App\Reports\Domain\Hint\LayerConditionEvaluator;
use App\Reports\Domain\Hint\LayerMeasurements;
use App\Reports\Domain\Hint\LayerWarningCode;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use PHPUnit\Framework\TestCase;

final class LayerConditionEvaluatorTest extends TestCase
{
    private LayerConditionEvaluator $evaluator;
    private PositiveNumberRange $dft;

    protected function setUp(): void
    {
        $this->evaluator = new LayerConditionEvaluator(new DewPointCalculator());
        $this->dft = new PositiveNumberRange(60, 100);
    }

    public function test_all_within_limits_produces_no_warnings(): void
    {
        $warnings = $this->evaluator->evaluate($this->dft, 10, new LayerMeasurements(
            dryFilmMean: 80, surfaceTemp: 25, airTemp: 20, humidity: 50,
        ));

        self::assertSame([], $warnings);
    }

    public function test_dry_film_below_min(): void
    {
        $warnings = $this->evaluator->evaluate($this->dft, 10, new LayerMeasurements(
            dryFilmMean: 50, surfaceTemp: 25, airTemp: 20, humidity: 50,
        ));

        self::assertCount(1, $warnings);
        self::assertSame(LayerWarningCode::DftBelowMin, $warnings[0]->code);
    }

    public function test_dry_film_above_max(): void
    {
        $warnings = $this->evaluator->evaluate($this->dft, 10, new LayerMeasurements(
            dryFilmMean: 120, surfaceTemp: 25, airTemp: 20, humidity: 50,
        ));

        self::assertCount(1, $warnings);
        self::assertSame(LayerWarningCode::DftAboveMax, $warnings[0]->code);
    }

    public function test_surface_below_application_temp(): void
    {
        // airTemp/humidity опущены → проверка точки росы пропускается, изолируем температуру нанесения.
        $warnings = $this->evaluator->evaluate($this->dft, 10, new LayerMeasurements(
            dryFilmMean: 80, surfaceTemp: 5,
        ));

        self::assertCount(1, $warnings);
        self::assertSame(LayerWarningCode::SurfaceBelowApplicationTemp, $warnings[0]->code);
    }

    public function test_condensation_risk(): void
    {
        // Воздух 20 °C, влажность 90 % → точка росы ≈ 18.3 °C, минимум поверхности ≈ 21.3 °C; поверхность 15 °C — риск.
        // applicationMinTemp=0, чтобы не сработала проверка температуры нанесения.
        $warnings = $this->evaluator->evaluate($this->dft, 0, new LayerMeasurements(
            dryFilmMean: 80, surfaceTemp: 15, airTemp: 20, humidity: 90,
        ));

        self::assertCount(1, $warnings);
        self::assertSame(LayerWarningCode::CondensationRisk, $warnings[0]->code);
    }

    public function test_partial_data_only_runs_available_checks(): void
    {
        // Только ТСП ниже минимума; температур нет — падать нельзя.
        $warnings = $this->evaluator->evaluate($this->dft, 10, new LayerMeasurements(dryFilmMean: 50));

        self::assertCount(1, $warnings);
        self::assertSame(LayerWarningCode::DftBelowMin, $warnings[0]->code);
    }

    public function test_empty_measurements_produce_no_warnings(): void
    {
        self::assertSame([], $this->evaluator->evaluate($this->dft, 10, new LayerMeasurements()));
    }
}
