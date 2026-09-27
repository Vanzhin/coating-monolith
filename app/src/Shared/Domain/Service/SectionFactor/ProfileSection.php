<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service\SectionFactor;

/**
 * Геометрия сечения профиля: даёт площадь поперечного сечения и обогреваемый периметр по схеме
 * обогрева. Дальше SectionFactorCalculator считает из них ПТМ и производные. Периметр — развёрнутый
 * контур (тонкослойная/контурная огнезащита).
 */
interface ProfileSection
{
    /** Площадь поперечного сечения металла, мм². */
    public function crossSectionArea(): float;

    /** Обогреваемый периметр по схеме обогрева, мм. */
    public function heatedPerimeter(HeatingScheme $scheme): float;
}
