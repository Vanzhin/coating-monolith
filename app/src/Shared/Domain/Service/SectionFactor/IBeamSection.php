<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service\SectionFactor;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Двутавр. На входе — полная высота H (аудит: репозиторный вход «ширина стенки» = h−2t неоднозначен,
 * ввод полной H тихо ломал результат; здесь высоту стенки считаем сами). Развёрнутый контур:
 * верх/низ — по ширине полки b, каждая боковая сторона — H + b − s (кромки полок + низы полок + стенка).
 * Закругления R (сопряжение стенка–полка) учитываем и в площади, и в периметре: каждая боковая сторона
 * несёт 2 скругления и сокращается на 2·R·(π/2−2) — порт getPerimeter из Ptm-Calculator (параллельные
 * полки, u=0). Уклон полок и наружный радиус (ГОСТ 8239/19425) не моделируем — в сортаменте только R.
 */
final readonly class IBeamSection implements ProfileSection
{
    /** Поправка длины дуги скругления против прямого угла (<0), как у уголка. */
    private const FILLET_PERIMETER = M_PI / 2 - 2;

    public function __construct(
        private float $height,
        private float $flangeWidth,
        private float $webThickness,
        private float $flangeThickness,
        private float $filletRadius = 0.0,
    ) {
        if ($height <= 0 || $flangeWidth <= 0 || $webThickness <= 0 || $flangeThickness <= 0) {
            throw new AppException('Размеры двутавра должны быть положительными.');
        }
        if ($filletRadius < 0) {
            throw new AppException('Радиус закругления не может быть отрицательным.');
        }
        if (2 * $flangeThickness >= $height) {
            throw new AppException('Суммарная толщина полок не помещается в высоту двутавра.');
        }
        if ($webThickness >= $flangeWidth) {
            throw new AppException('Толщина стенки не может превышать ширину полки.');
        }
    }

    public function crossSectionArea(): float
    {
        $webHeight = $this->height - 2 * $this->flangeThickness;
        $filletArea = 4 * $this->filletRadius ** 2 * (1 - M_PI / 4);

        return $webHeight * $this->webThickness + 2 * $this->flangeWidth * $this->flangeThickness + $filletArea;
    }

    public function heatedPerimeter(HeatingScheme $scheme): float
    {
        // Боковая сторона: стенка + низы полок + кромки минус 2 скругления стенка–полка (верх+низ).
        $side = $this->height + $this->flangeWidth - $this->webThickness
            + 2 * $this->filletRadius * self::FILLET_PERIMETER;
        $perimeter = 0.0;
        if ($scheme->top) {
            $perimeter += $this->flangeWidth;
        }
        if ($scheme->bottom) {
            $perimeter += $this->flangeWidth;
        }
        if ($scheme->left) {
            $perimeter += $side;
        }
        if ($scheme->right) {
            $perimeter += $side;
        }

        return $perimeter;
    }
}
