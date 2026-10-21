<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service\SectionFactor;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Профиль квадратный/прямоугольный (полый). Площадь стенок 2·t·(H+W−2t) минус поправка на наружные
 * закругления R. Труба замкнутая — обогревается по наружному контуру: верх/низ по ширине W, бока по
 * высоте H; каждая обогреваемая сторона несёт один скруглённый угол — наружный контур режется на
 * R·(π/2−2). На 4 стороны это точный периметр скруглённого прямоугольника 2(H+W)+4R(π/2−2). Эмпирические
 * radCoef эталона для R≠2t не копируем — наружный контур зависит только от R, не от толщины стенки.
 */
final readonly class RectHollowSection implements ProfileSection
{
    /** Поправка длины дуги скругления угла против прямого угла (<0), как у уголка/двутавра. */
    private const CORNER_PERIMETER = M_PI / 2 - 2;

    public function __construct(
        private float $height,
        private float $width,
        private float $wallThickness,
        private float $outerRadius = 0.0,
    ) {
        if ($height <= 0 || $width <= 0 || $wallThickness <= 0) {
            throw new AppException('Размеры профиля должны быть положительными.');
        }
        if ($outerRadius < 0) {
            throw new AppException('Радиус профиля не может быть отрицательным.');
        }
        if (2 * $wallThickness >= $height || 2 * $wallThickness >= $width) {
            throw new AppException('Толщина стенки профиля не помещается в сечение.');
        }
    }

    public function crossSectionArea(): float
    {
        $base = 2 * $this->wallThickness * ($this->height + $this->width - 2 * $this->wallThickness);
        if ($this->outerRadius <= 0) {
            return $base;
        }
        $innerRadius = max($this->outerRadius - $this->wallThickness, 0.0);

        return $base - (4 - M_PI) * ($this->outerRadius ** 2 - $innerRadius ** 2);
    }

    public function heatedPerimeter(HeatingScheme $scheme): float
    {
        // Каждая обогреваемая сторона несёт один скруглённый угол: наружный контур режется на R·(π/2−2).
        $corner = $this->outerRadius * self::CORNER_PERIMETER;
        $perimeter = 0.0;
        if ($scheme->top) {
            $perimeter += $this->width + $corner;
        }
        if ($scheme->bottom) {
            $perimeter += $this->width + $corner;
        }
        if ($scheme->left) {
            $perimeter += $this->height + $corner;
        }
        if ($scheme->right) {
            $perimeter += $this->height + $corner;
        }

        return $perimeter;
    }
}
