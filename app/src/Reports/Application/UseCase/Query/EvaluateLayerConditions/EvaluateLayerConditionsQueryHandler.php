<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\EvaluateLayerConditions;

use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Service\DewPointCalculator;
use App\Reports\Domain\Hint\ColorPalette;
use App\Reports\Domain\Hint\LayerConditionEvaluator;
use App\Reports\Domain\Hint\LayerMeasurements;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Aggregate\ValueObject\Percent;

/**
 * Читает пороги покрытия из каталога (кросс-контекст, как CreateReport читает систему) и прогоняет
 * замеры слоя через доменное правило. Покрытие не найдено → пустой список (без подсказок по порогам).
 */
final readonly class EvaluateLayerConditionsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface $coatings,
        private LayerConditionEvaluator $evaluator,
        private DewPointCalculator $dewPoint,
    ) {
    }

    public function __invoke(EvaluateLayerConditionsQuery $query): EvaluateLayerConditionsQueryResult
    {
        $measurements = new LayerMeasurements($query->dryFilmMean, $query->surfaceTemp, $query->airTemp, $query->humidity, $query->color);

        // Точка росы — производная от t воздуха и влажности, НЕ зависит от покрытия; считаем всегда
        // (поле в форме неактивно, значение отсюда).
        $dewPoint = null !== $query->airTemp && null !== $query->humidity && $query->humidity > 0 && $query->humidity <= 100
            ? round($this->dewPoint->dewPoint($query->airTemp, new Percent($query->humidity)), 1)
            : null;

        // Риск конденсата зависит только от климата — подсказываем и без выбранного покрытия.
        $warnings = $this->evaluator->evaluateClimate($measurements);

        // Пороговые подсказки (цвет/ТСП/t нанесения) — только когда покрытие выбрано и найдено.
        $coating = '' !== $query->coatingId ? $this->coatings->findOneById($query->coatingId) : null;
        if (null !== $coating) {
            $labels = [];
            foreach ($coating->getPossibleColors() as $color) {
                foreach ([$color->getName(), $color->getRal(), $color->label()] as $label) {
                    if (null !== $label && '' !== $label) {
                        $labels[] = $label;
                    }
                }
            }

            $warnings = array_merge($this->evaluator->evaluateAgainstCoating(
                $coating->getDftRange()->range,
                $coating->getApplicationMinTemp(),
                new ColorPalette($coating->isTintable(), $labels),
                $measurements,
            ), $warnings);
        }

        return new EvaluateLayerConditionsQueryResult($warnings, $dewPoint);
    }
}
