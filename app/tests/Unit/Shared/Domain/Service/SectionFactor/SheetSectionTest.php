<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Service\SectionFactor;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\Service\SectionFactor\HeatingScheme;
use App\Shared\Domain\Service\SectionFactor\SheetSection;
use App\Shared\Domain\Service\SectionFactorCalculator;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class SheetSectionTest extends TestCase
{
    private function ptm(SheetSection $sheet, HeatingScheme $scheme): float
    {
        return (new SectionFactorCalculator())->reducedThickness(
            new PositiveNumber($sheet->crossSectionArea()),
            new PositiveNumber($sheet->heatedPerimeter($scheme)),
        );
    }

    public function test_reduced_thickness_both_faces_is_half_thickness(): void
    {
        // лист 10 мм, обогрев с двух сторон: ПТМ = δ/2 = 5
        self::assertEqualsWithDelta(5.0, $this->ptm(new SheetSection(10), HeatingScheme::allSides()), 0.001);
    }

    public function test_reduced_thickness_one_face_is_full_thickness(): void
    {
        // одна плоскость (лист на стене): ПТМ = δ = 10
        $topOnly = new HeatingScheme(true, false, false, false);
        self::assertEqualsWithDelta(10.0, $this->ptm(new SheetSection(10), $topOnly), 0.001);
    }

    public function test_rejects_scheme_without_large_face(): void
    {
        // у листа обогреваются только большие плоскости (top/bottom); кромки не считаем
        $this->expectException(AppException::class);
        (new SheetSection(10))->heatedPerimeter(new HeatingScheme(false, false, true, true));
    }

    public function test_rejects_non_positive_thickness(): void
    {
        $this->expectException(AppException::class);
        new SheetSection(0);
    }
}
