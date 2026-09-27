<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service\SectionFactor;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Швеллер. Спинка (левая сторона) — по высоте H; открытая (правая) сторона — развёрнутый контур
 * полок с учётом уклона u (ГОСТ 8240) и закруглений R/r. При u=0 (DIN, параллельные полки) уклонный
 * член вырождается в 2·(b−s). Формулы портированы из Ptm-Calculator (тригонометрия уклона/закруглений).
 */
final readonly class ChannelSection implements ProfileSection
{
    public function __construct(
        private float $height,
        private float $flangeWidth,
        private float $webThickness,
        private float $flangeThickness,
        private float $innerRadius = 0.0,
        private float $outerRadius = 0.0,
        private float $slope = 0.0,
    ) {
        if ($height <= 0 || $flangeWidth <= 0 || $webThickness <= 0 || $flangeThickness <= 0) {
            throw new AppException('Размеры швеллера должны быть положительными.');
        }
        if ($innerRadius < 0 || $outerRadius < 0 || $slope < 0) {
            throw new AppException('Радиусы и уклон швеллера не могут быть отрицательными.');
        }
        if ($webThickness >= $flangeWidth) {
            throw new AppException('Толщина стенки не может превышать ширину полки.');
        }
        if (2 * $flangeThickness >= $height) {
            throw new AppException('Толщины полок не помещаются в высоту швеллера.');
        }
    }

    public function crossSectionArea(): float
    {
        $slopeAngle = atan($this->slope);
        $area = $this->height * $this->webThickness
            + 2 * ($this->flangeWidth - $this->webThickness) * $this->flangeThickness;
        $area += 2 * $this->innerRadiusAreaCorrection($this->innerRadius, $slopeAngle);
        $area -= 2 * $this->innerRadiusAreaCorrection($this->outerRadius, $slopeAngle);

        return $area;
    }

    public function heatedPerimeter(HeatingScheme $scheme): float
    {
        $slopeAngle = atan($this->slope);
        $perimeter = 0.0;
        if ($scheme->top) {
            $perimeter += $this->flangeWidth;
        }
        if ($scheme->bottom) {
            $perimeter += $this->flangeWidth;
        }
        if ($scheme->left) {
            $perimeter += $this->height;
        }
        if ($scheme->right) {
            $perimeter += $this->height
                + 2 * ($this->flangeWidth - $this->webThickness) * (1 / cos($slopeAngle) - $this->slope)
                + 2 * $this->radiusPerimeterCorrection($this->innerRadius, $slopeAngle)
                + 2 * $this->radiusPerimeterCorrection($this->outerRadius, $slopeAngle);
        }

        return $perimeter;
    }

    /** Поправка площади на закругление с учётом уклона полки (по Ptm-Calculator). */
    private function innerRadiusAreaCorrection(float $radius, float $slopeAngle): float
    {
        $half = deg2rad((90 + rad2deg($slopeAngle)) / 2);

        return $radius * $radius / tan($half) - $radius * $radius * M_PI * (90 - rad2deg($slopeAngle)) / 360;
    }

    /** Поправка периметра на закругление с учётом уклона полки (по Ptm-Calculator). */
    private function radiusPerimeterCorrection(float $radius, float $slopeAngle): float
    {
        $halfDeg = 90 - (90 + rad2deg($slopeAngle)) / 2;

        return 2 * $radius * M_PI * 2 * $halfDeg / 360 - 2 * $radius * tan(deg2rad($halfDeg));
    }
}
