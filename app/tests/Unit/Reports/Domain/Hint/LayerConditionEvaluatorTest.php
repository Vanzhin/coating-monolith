<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports\Domain\Hint;

use App\Coatings\Domain\Service\DewPointCalculator;
use App\Reports\Domain\Hint\ColorPalette;
use App\Reports\Domain\Hint\LayerConditionEvaluator;
use App\Reports\Domain\Hint\LayerMeasurements;
use App\Reports\Domain\Hint\LayerWarningCode;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use PHPUnit\Framework\TestCase;

final class LayerConditionEvaluatorTest extends TestCase
{
    private LayerConditionEvaluator $evaluator;
    private PositiveNumberRange $dft;
    private ColorPalette $palette;

    protected function setUp(): void
    {
        $this->evaluator = new LayerConditionEvaluator(new DewPointCalculator());
        $this->dft = new PositiveNumberRange(60, 100);
        $this->palette = new ColorPalette(false, ['Серый', 'RAL 7040']);
    }

    public function test_all_within_limits_produces_no_warnings(): void
    {
        $warnings = $this->evaluator->evaluateAgainstCoating($this->dft, 10, $this->palette, new LayerMeasurements(
            dryFilmMean: 80, surfaceTemp: 25, airTemp: 20, humidity: 50,
        ));

        self::assertSame([], $warnings);
    }

    public function test_dry_film_below_min(): void
    {
        $warnings = $this->evaluator->evaluateAgainstCoating($this->dft, 10, $this->palette, new LayerMeasurements(
            dryFilmMean: 50, surfaceTemp: 25, airTemp: 20, humidity: 50,
        ));

        self::assertCount(1, $warnings);
        self::assertSame(LayerWarningCode::DftBelowMin, $warnings[0]->code);
    }

    public function test_dry_film_above_max(): void
    {
        $warnings = $this->evaluator->evaluateAgainstCoating($this->dft, 10, $this->palette, new LayerMeasurements(
            dryFilmMean: 120, surfaceTemp: 25, airTemp: 20, humidity: 50,
        ));

        self::assertCount(1, $warnings);
        self::assertSame(LayerWarningCode::DftAboveMax, $warnings[0]->code);
    }

    public function test_surface_below_application_temp(): void
    {
        $warnings = $this->evaluator->evaluateAgainstCoating($this->dft, 10, $this->palette, new LayerMeasurements(
            dryFilmMean: 80, surfaceTemp: 5,
        ));

        self::assertCount(1, $warnings);
        self::assertSame(LayerWarningCode::SurfaceBelowApplicationTemp, $warnings[0]->code);
    }

    public function test_condensation_risk_is_climate_only(): void
    {
        // Воздух 20 °C, влажность 90 % → точка росы ≈ 18.3 °C, минимум поверхности ≈ 21.3 °C; поверхность 15 °C — риск.
        // Покрытие не нужно: климатическая проверка.
        $warnings = $this->evaluator->evaluateClimate(new LayerMeasurements(surfaceTemp: 15, airTemp: 20, humidity: 90));

        self::assertCount(1, $warnings);
        self::assertSame(LayerWarningCode::CondensationRisk, $warnings[0]->code);
    }

    public function test_climate_no_risk_when_surface_warm_enough(): void
    {
        self::assertSame([], $this->evaluator->evaluateClimate(new LayerMeasurements(surfaceTemp: 25, airTemp: 20, humidity: 50)));
    }

    public function test_climate_skipped_on_partial_data(): void
    {
        // Нет t поверхности/воздуха/влажности — проверку конденсата пропускаем.
        self::assertSame([], $this->evaluator->evaluateClimate(new LayerMeasurements(surfaceTemp: 4)));
    }

    public function test_partial_data_only_runs_available_checks(): void
    {
        // Только ТСП ниже минимума; температур нет — падать нельзя.
        $warnings = $this->evaluator->evaluateAgainstCoating($this->dft, 10, $this->palette, new LayerMeasurements(dryFilmMean: 50));

        self::assertCount(1, $warnings);
        self::assertSame(LayerWarningCode::DftBelowMin, $warnings[0]->code);
    }

    public function test_empty_measurements_produce_no_warnings(): void
    {
        self::assertSame([], $this->evaluator->evaluateAgainstCoating($this->dft, 10, $this->palette, new LayerMeasurements()));
    }

    public function test_color_not_in_palette_warns(): void
    {
        $warnings = $this->evaluator->evaluateAgainstCoating($this->dft, 10, $this->palette, new LayerMeasurements(color: 'розовый'));

        self::assertCount(1, $warnings);
        self::assertSame(LayerWarningCode::ColorNotInPalette, $warnings[0]->code);
    }

    public function test_color_in_palette_no_warning(): void
    {
        // Регистр не важен: «серый» ↔ «Серый» в палитре.
        self::assertSame([], $this->evaluator->evaluateAgainstCoating($this->dft, 10, $this->palette, new LayerMeasurements(color: 'серый')));
    }

    public function test_tintable_coating_accepts_any_color(): void
    {
        $tintable = new ColorPalette(true, []);
        self::assertSame([], $this->evaluator->evaluateAgainstCoating($this->dft, 10, $tintable, new LayerMeasurements(color: 'розовый')));
    }
}
