<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service\SectionFactor;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Уголок (равно- и неравнополочный). Площадь — прямоугольная часть (b1+b2−t)·t плюс поправки на
 * наружное закругление (добавляет металл) и два внутренних (убавляют).
 *
 * Периметр — три независимые обогреваемые поверхности: наружная полка b1 (left), наружная полка b2
 * (top) и внутренний контур (right) — вогнутая грань со скруглёнными носками (r) и пяткой (R).
 * Все три включены = полный контур 2·(b1+b2). Прижата полка к стене → снять её грань; уголок зашит
 * в составное сечение → снять внутренний контур. Скругления R/r несёт внутренний контур.
 *
 * Базовые формулы портированы из Ptm-Calculator; в оригинале внутренний контур считался всегда —
 * здесь он вынесен в отдельную переключаемую грань (режим «все включены» даёт тот же результат).
 */
final readonly class AngleSection implements ProfileSection
{
    private const CORNER_AREA = 1 - M_PI / 4;   // доля квадрата радиуса в площади скругления
    private const CORNER_PERIMETER = M_PI / 2 - 2; // поправка длины дуги против прямого угла

    public function __construct(
        private float $legA,
        private float $legB,
        private float $thickness,
        private float $outerRadius = 0.0,
        private float $innerRadius = 0.0,
    ) {
        if ($legA <= 0 || $legB <= 0 || $thickness <= 0) {
            throw new AppException('Размеры уголка должны быть положительными.');
        }
        if ($outerRadius < 0 || $innerRadius < 0) {
            throw new AppException('Радиусы уголка не могут быть отрицательными.');
        }
        if ($thickness >= $this->legA || $thickness >= $this->legB) {
            throw new AppException('Толщина уголка не может превышать длину полки.');
        }
    }

    public function crossSectionArea(): float
    {
        return ($this->legA + $this->legB - $this->thickness) * $this->thickness
            + $this->outerRadius ** 2 * self::CORNER_AREA
            - 2 * $this->innerRadius ** 2 * self::CORNER_AREA;
    }

    public function heatedPerimeter(HeatingScheme $scheme): float
    {
        $perimeter = 0.0;
        if ($scheme->left) {                 // наружная полка b1
            $perimeter += $this->legA;
        }
        if ($scheme->top) {                  // наружная полка b2
            $perimeter += $this->legB;
        }
        if ($scheme->right) {                // внутренний контур + скругления носков (r) и пятки (R)
            $perimeter += $this->legA + $this->legB
                + $this->outerRadius * self::CORNER_PERIMETER
                + 2 * $this->innerRadius * self::CORNER_PERIMETER;
        }

        return $perimeter;
    }
}
