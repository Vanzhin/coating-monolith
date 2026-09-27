<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;

/**
 * Приведённая толщина металла (ПТМ) и производные величины по НПБ 236-97 / ГОСТ Р 53295:
 * ПТМ = площадь поперечного сечения металла / обогреваемый периметр. Чем меньше ПТМ, тем быстрее
 * профиль прогревается и тем толще нужен слой огнезащиты.
 *
 * Ядро намеренно узкое — считает из уже посчитанных площади и обогреваемого периметра; геометрия
 * профиля (размеры + схема обогрева → площадь, периметр) живёт отдельно (ProfileSection). Чистая
 * физика, не привязана к контексту (используют раздел «Инструменты» и будущий подбор огнезащиты) →
 * живёт в Shared. Фронт дублирует эти формулы лишь ради офлайна.
 */
final class SectionFactorCalculator
{
    /** Плотность стали, кг/дм³ (7850 кг/м³). */
    public const STEEL_DENSITY = 7.85;

    /** ПТМ (мм) = площадь сечения (мм²) / обогреваемый периметр (мм). */
    public function reducedThickness(PositiveNumber $area, PositiveNumber $perimeter): float
    {
        return $area->value() / $perimeter->value();
    }

    /** Площадь поверхности на 1 м длины (м²) = периметр (мм) / 1000. */
    public function surfaceAreaPerMeter(PositiveNumber $perimeter): float
    {
        return $perimeter->value() / 1000;
    }

    /** Масса 1 м (кг) = площадь сечения (мм²) / 1000 × плотность стали. */
    public function massPerMeter(PositiveNumber $area): float
    {
        return $area->value() / 1000 * self::STEEL_DENSITY;
    }

    /** Площадь поверхности на 1 т (м²) = (1000 кг / масса 1 м) × площадь поверхности 1 м. */
    public function surfaceAreaPerTon(PositiveNumber $area, float $surfaceAreaPerMeter): float
    {
        return 1000 / $this->massPerMeter($area) * $surfaceAreaPerMeter;
    }
}
