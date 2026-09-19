<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\EvaluateLayerConditions;

use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Reports\Domain\Hint\LayerConditionEvaluator;
use App\Reports\Domain\Hint\LayerMeasurements;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Читает пороги покрытия из каталога (кросс-контекст, как CreateReport читает систему) и прогоняет
 * замеры слоя через доменное правило. Покрытие не найдено → пустой список (без подсказок по порогам).
 */
final readonly class EvaluateLayerConditionsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface $coatings,
        private LayerConditionEvaluator $evaluator,
    ) {
    }

    public function __invoke(EvaluateLayerConditionsQuery $query): EvaluateLayerConditionsQueryResult
    {
        $coating = $this->coatings->findOneById($query->coatingId);
        if (null === $coating) {
            return new EvaluateLayerConditionsQueryResult([]);
        }

        $warnings = $this->evaluator->evaluate(
            $coating->getDftRange()->range,
            $coating->getApplicationMinTemp(),
            new LayerMeasurements($query->dryFilmMean, $query->surfaceTemp, $query->airTemp, $query->humidity),
        );

        return new EvaluateLayerConditionsQueryResult($warnings);
    }
}
