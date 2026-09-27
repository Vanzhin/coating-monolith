<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service\SectionFactor;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Профиль квадратный/прямоугольный (полый). Площадь стенок 2·t·(H+W−2t) минус поправка на наружные
 * закругления R. Труба замкнутая — обогревается по наружному контуру: верх/низ по ширине W,
 * бока по высоте H; закругление в периметре не учитываем (прямой контур).
 */
final readonly class RectHollowSection implements ProfileSection
{
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
        $perimeter = 0.0;
        if ($scheme->top) {
            $perimeter += $this->width;
        }
        if ($scheme->bottom) {
            $perimeter += $this->width;
        }
        if ($scheme->left) {
            $perimeter += $this->height;
        }
        if ($scheme->right) {
            $perimeter += $this->height;
        }

        return $perimeter;
    }
}
