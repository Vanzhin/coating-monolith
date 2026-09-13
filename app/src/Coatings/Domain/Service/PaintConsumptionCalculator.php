<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Service;

use App\Shared\Domain\Aggregate\ValueObject\Percent;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Расход лакокрасочного материала. Строится на физике толщины плёнки (переиспользует
 * FilmThicknessCalculator): расход неразбавленной краски = объём мокрой плёнки на м².
 *   л/м² (теор.) = WFT(мкм) × 10⁻³ = DFT × 100 / VS × 10⁻³ = DFT / (VS × 10).
 *
 * Разбавление на расход НЕ влияет (растворитель испаряется, солидов столько же) — считаем по
 * неразбавленной краске. Практический расход = теоретический / (1 − потери/100): 30% потерь →
 * на поверхность легло 70% → ×1.43. Источник истины на бэке; фронт дублирует формулу ради офлайна.
 */
final class PaintConsumptionCalculator
{
    public function __construct(private FilmThicknessCalculator $filmThickness)
    {
    }

    /** Практический расход неразбавленной краски (л/м²) для сухой плёнки с учётом потерь. */
    public function litersPerSquareMeter(Percent $volumeSolids, PositiveNumber $dryMicrons, Percent $loss): float
    {
        // Разбавление 0 %: расход считаем по неразбавленной краске.
        $wetMicrons = $this->filmThickness->wetFromDry($volumeSolids, new Percent(0), $dryMicrons);

        return $wetMicrons / 1000 * $this->lossCoefficient($loss);
    }

    /**
     * Коэффициент на потери: 30% потерь → легло 70% → расход ×1/0.7≈1.43. Потери 100% не имеют
     * смысла (бесконечный расход) — отсекаем. Публичный: фронт показывает связку «потери ⇄ коэффициент».
     */
    public function lossCoefficient(Percent $loss): float
    {
        if ($loss->value() >= 100) {
            throw new AppException('Потери должны быть меньше 100 %.');
        }

        return 1 / (1 - $loss->value() / 100);
    }

    /** Сколько всего краски (л) нужно на заданную площадь. */
    public function totalLiters(PositiveNumber $litersPerSquareMeter, PositiveNumber $areaSquareMeters): float
    {
        return $litersPerSquareMeter->value() * $areaSquareMeters->value();
    }

    /** Какую площадь (м²) покроет заданный объём краски. */
    public function coverageSquareMeters(PositiveNumber $litersPerSquareMeter, PositiveNumber $liters): float
    {
        return $liters->value() / $litersPerSquareMeter->value();
    }

    /** Сколько единиц фасовки (вёдер/банок) нужно на объём — округление вверх (неполную докупаем). */
    public function packsNeeded(PositiveNumber $totalLiters, PositiveNumber $packLiters): int
    {
        return (int) ceil($totalLiters->value() / $packLiters->value());
    }

    /** Масса (кг) из объёма (л) по плотности (кг/л). */
    public function massKilograms(PositiveNumber $liters, PositiveNumber $densityKgPerLiter): float
    {
        return $liters->value() * $densityKgPerLiter->value();
    }
}
