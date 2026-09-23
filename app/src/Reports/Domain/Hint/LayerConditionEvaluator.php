<?php

declare(strict_types=1);

namespace App\Reports\Domain\Hint;

use App\Shared\Domain\Aggregate\ValueObject\Percent;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\DewPointCalculator;

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
     * Климатические подсказки — НЕ зависят от покрытия: риск конденсата (t поверхности vs точка росы,
     * запас по ISO 8502-4). Считается всегда, когда есть замеры воздуха/влажности/поверхности.
     *
     * @return list<LayerWarning>
     */
    public function evaluateClimate(LayerMeasurements $m): array
    {
        if (null === $m->surfaceTemp || null === $m->airTemp || null === $m->humidity || $m->humidity <= 0 || $m->humidity > 100) {
            return [];
        }

        $dewPoint = $this->dewPoint->dewPoint($m->airTemp, new Percent($m->humidity));
        if ($this->dewPoint->isSurfaceAcceptable($m->surfaceTemp, $dewPoint)) {
            return [];
        }

        return [new LayerWarning(
            LayerWarningCode::CondensationRisk,
            sprintf(
                'Риск конденсата: температура поверхности %s °C ниже допустимой %.1f °C (точка росы %.1f °C + запас 3 °C).',
                $m->surfaceTemp,
                $this->dewPoint->minSurfaceTemperature($dewPoint),
                $dewPoint,
            ),
        )];
    }

    /**
     * Подсказки против порогов покрытия: цвет вне палитры, ТСП вне диапазона, поверхность холоднее
     * минимальной температуры нанесения. Требуют загруженного покрытия.
     *
     * @return list<LayerWarning>
     */
    public function evaluateAgainstCoating(PositiveNumberRange $dft, int $applicationMinTemp, ColorPalette $palette, LayerMeasurements $m): array
    {
        $warnings = [];

        if (null !== $m->color && '' !== trim($m->color) && !$palette->accepts($m->color)) {
            $warnings[] = new LayerWarning(LayerWarningCode::ColorNotInPalette, sprintf('Цвет «%s» не входит в палитру покрытия.', trim($m->color)));
        }

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

        return $warnings;
    }
}
