<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service\SectionFactor;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Труба (полая круглая). Площадь — кольцо π/4·(D²−(D−2t)²). Обогревается по всему наружному контуру,
 * поэтому обогреваемый периметр = π·D независимо от схемы граней.
 */
final readonly class PipeSection implements ProfileSection
{
    public function __construct(
        private float $outerDiameter,
        private float $wallThickness,
    ) {
        if ($outerDiameter <= 0 || $wallThickness <= 0) {
            throw new AppException('Размеры трубы должны быть положительными.');
        }
        if ($wallThickness >= $outerDiameter / 2) {
            throw new AppException('Толщина стенки трубы не может превышать её радиус.');
        }
    }

    public function crossSectionArea(): float
    {
        $innerDiameter = $this->outerDiameter - 2 * $this->wallThickness;

        return M_PI / 4 * ($this->outerDiameter ** 2 - $innerDiameter ** 2);
    }

    public function heatedPerimeter(HeatingScheme $scheme): float
    {
        return M_PI * $this->outerDiameter;
    }
}
