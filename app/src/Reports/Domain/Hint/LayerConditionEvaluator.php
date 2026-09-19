<?php

declare(strict_types=1);

namespace App\Reports\Domain\Hint;

use App\Coatings\Domain\Service\DewPointCalculator;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;

/**
 * Мягкие подсказки при заполнении слоя: сверяет фактические замеры с порогами покрытия и физикой
 * точки росы. Ничего НЕ блокирует — только советует. Источник истины на бэке; фронт дублирует
 * формулы ради офлайна. Частичные данные черновика: чего не хватает — ту проверку пропускаем.
 */
final readonly class LayerConditionEvaluator
{
    public function __construct(private DewPointCalculator $dewPoint)
    {
    }

    /**
     * @return list<LayerWarning>
     */
    public function evaluate(PositiveNumberRange $dft, int $applicationMinTemp, LayerMeasurements $m): array
    {
        $warnings = [];

        if (null !== $m->dryFilmMean) {
            if ($m->dryFilmMean < $dft->getMin()) {
                $warnings[] = new LayerWarning(LayerWarningCode::DftBelowMin, sprintf('ТСП %s мкм ниже минимума покрытия %s мкм.', $m->dryFilmMean, $dft->getMin()));
            } elseif ($m->dryFilmMean > $dft->getMax()) {
                $warnings[] = new LayerWarning(LayerWarningCode::DftAboveMax, sprintf('ТСП %s мкм выше максимума покрытия %s мкм.', $m->dryFilmMean, $dft->getMax()));
            }
        }

        if (null !== $m->surfaceTemp && $m->surfaceTemp < $applicationMinTemp) {
            $warnings[] = new LayerWarning(LayerWarningCode::SurfaceBelowApplicationTemp, sprintf('Температура поверхности %s °C ниже минимальной для нанесения %d °C.', $m->surfaceTemp, $applicationMinTemp));
        }

        if (null !== $m->surfaceTemp && null !== $m->airTemp && null !== $m->humidity && $m->humidity > 0 && $m->humidity <= 100) {
            $dewPoint = $this->dewPoint->dewPoint($m->airTemp, new Percent($m->humidity));
            if (!$this->dewPoint->isSurfaceAcceptable($m->surfaceTemp, $dewPoint)) {
                $warnings[] = new LayerWarning(
                    LayerWarningCode::CondensationRisk,
                    sprintf(
                        'Риск конденсата: температура поверхности %s °C ниже допустимой %.1f °C (точка росы %.1f °C + запас 3 °C).',
                        $m->surfaceTemp,
                        $this->dewPoint->minSurfaceTemperature($dewPoint),
                        $dewPoint,
                    ),
                );
            }
        }

        return $warnings;
    }
}
