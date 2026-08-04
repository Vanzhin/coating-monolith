<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\RecoatingInterpolationModel;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class RecoatingInterpolationModelTest extends TestCase
{
    public function test_has_two_cases(): void
    {
        $values = array_map(static fn (RecoatingInterpolationModel $c) => $c->value, RecoatingInterpolationModel::cases());
        sort($values);
        $this->assertSame(['LINEAR', 'STEP'], $values);
    }

    public function test_linear_scales_proportionally_to_thickness(): void
    {
        $result = RecoatingInterpolationModel::LINEAR->interpolate(
            sourceDft: 100,
            targetDft: 200,
            sourceMinutes: 240,
        );

        $this->assertSame(480, $result);
    }

    public function test_linear_reduces_for_thinner_layer(): void
    {
        $result = RecoatingInterpolationModel::LINEAR->interpolate(
            sourceDft: 100,
            targetDft: 50,
            sourceMinutes: 240,
        );

        $this->assertSame(120, $result);
    }

    public function test_linear_returns_source_when_target_equals_source(): void
    {
        $result = RecoatingInterpolationModel::LINEAR->interpolate(
            sourceDft: 100,
            targetDft: 100,
            sourceMinutes: 240,
        );

        $this->assertSame(240, $result);
    }

    public function test_linear_rounds_result(): void
    {
        // 240 * 33 / 100 = 79.2 → 79
        $result = RecoatingInterpolationModel::LINEAR->interpolate(
            sourceDft: 100,
            targetDft: 33,
            sourceMinutes: 240,
        );

        $this->assertSame(79, $result);
    }

    public function test_step_ignores_target_thickness(): void
    {
        $result = RecoatingInterpolationModel::STEP->interpolate(
            sourceDft: 100,
            targetDft: 500,
            sourceMinutes: 240,
        );

        $this->assertSame(240, $result);
    }

    public function test_throws_on_non_positive_source_dft(): void
    {
        $this->expectException(AppException::class);
        RecoatingInterpolationModel::LINEAR->interpolate(0, 100, 60);
    }

    public function test_throws_on_non_positive_target_dft(): void
    {
        $this->expectException(AppException::class);
        RecoatingInterpolationModel::LINEAR->interpolate(100, 0, 60);
    }

    public function test_throws_on_non_positive_source_minutes(): void
    {
        $this->expectException(AppException::class);
        RecoatingInterpolationModel::LINEAR->interpolate(100, 100, 0);
    }
}
