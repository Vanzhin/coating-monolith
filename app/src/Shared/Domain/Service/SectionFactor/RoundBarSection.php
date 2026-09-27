<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service\SectionFactor;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Круг (сплошной пруток). Площадь π·D²/4, обогреваемый периметр π·D → ПТМ = D/4.
 * Обогрев по всему контуру, схема граней не применяется.
 */
final readonly class RoundBarSection implements ProfileSection
{
    public function __construct(private float $diameter)
    {
        if ($diameter <= 0) {
            throw new AppException('Диаметр круга должен быть положительным.');
        }
    }

    public function crossSectionArea(): float
    {
        return M_PI * $this->diameter ** 2 / 4;
    }

    public function heatedPerimeter(HeatingScheme $scheme): float
    {
        return M_PI * $this->diameter;
    }
}
