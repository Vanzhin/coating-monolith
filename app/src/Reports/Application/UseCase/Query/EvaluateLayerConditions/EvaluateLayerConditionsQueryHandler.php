<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\EvaluateLayerConditions;

use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQuery;
use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQueryResult;
use App\Reports\Domain\Hint\ColorPalette;
use App\Reports\Domain\Hint\LayerConditionEvaluator;
use App\Reports\Domain\Hint\LayerMeasurements;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\DewPointCalculator;

/**
 * Читает пороги покрытия из каталога через опубликованный query Coatings (DTO, не домен/репозиторий)
 * и прогоняет замеры слоя через доменное правило. Покрытие не найдено → пустой список порогов.
 */
final readonly class EvaluateLayerConditionsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
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

        // Пороговые подсказки (цвет/ТСП/t нанесения) — только когда покрытие выбрано и найдено в каталоге.
        $coating = null;
        if ('' !== $query->coatingId) {
            $result = $this->queryBus->execute(new GetCoatingQuery($query->coatingId));
            $coating = $result instanceof GetCoatingQueryResult ? $result->coatingDTO : null;
        }
        if (null !== $coating) {
            $labels = [];
            foreach ($coating->possibleColors as $color) {
                foreach ([$color->name, $color->ral, $color->label] as $label) {
                    if (null !== $label && '' !== $label) {
                        $labels[] = $label;
                    }
                }
            }

            $warnings = array_merge($this->evaluator->evaluateAgainstCoating(
                new PositiveNumberRange($coating->dftRange->min, $coating->dftRange->max),
                $coating->applicationMinTemp,
                new ColorPalette($coating->isTintable, $labels),
                $measurements,
            ), $warnings);
        }

        return new EvaluateLayerConditionsQueryResult($warnings, $dewPoint);
    }
}
