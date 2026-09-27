<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service\SectionFactor;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Лист (плоский). ПТМ = толщина / число обогреваемых плоскостей: две стороны → δ/2, одна → δ.
 * Считаем на расчётной полосе шириной 1 м, кромки листа не учитываем (аудит: убрана хардкод-таблица
 * коэффициентов репозитория — плотность и число граней дают всё напрямую).
 */
final readonly class SheetSection implements ProfileSection
{
    private const REFERENCE_WIDTH_MM = 1000.0;

    public function __construct(private float $thickness)
    {
        if ($thickness <= 0) {
            throw new AppException('Толщина листа должна быть положительной.');
        }
    }

    public function crossSectionArea(): float
    {
        return $this->thickness * self::REFERENCE_WIDTH_MM;
    }

    public function heatedPerimeter(HeatingScheme $scheme): float
    {
        $faces = ($scheme->top ? 1 : 0) + ($scheme->bottom ? 1 : 0);
        if (0 === $faces) {
            throw new AppException('У листа обогреваются большие плоскости — укажите верхнюю и/или нижнюю сторону.');
        }

        return $faces * self::REFERENCE_WIDTH_MM;
    }
}
