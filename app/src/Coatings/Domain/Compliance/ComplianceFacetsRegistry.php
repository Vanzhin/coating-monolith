<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance;

use App\Coatings\Domain\Compliance\Facet\StandardFacets;

/**
 * Реестр самоописаний стандартов: собирает facets() со всех зарегистрированных StandardEvaluator.
 * Единый источник для показа (подпись бейджа) и фильтра (варианты осей, «≥»-раскрытие). Добавление
 * стандарта = новый оценщик с тем же тегом; реестр и его потребители не трогаются.
 */
final readonly class ComplianceFacetsRegistry
{
    /**
     * @param iterable<StandardEvaluator> $evaluators
     */
    public function __construct(private iterable $evaluators)
    {
    }

    public function facetsFor(ComplianceStandard $standard): ?StandardFacets
    {
        foreach ($this->evaluators as $evaluator) {
            if ($evaluator->supports($standard)) {
                return $evaluator->facets();
            }
        }

        return null;
    }

    /**
     * @return list<StandardFacets> по одному на зарегистрированный стандарт
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->evaluators as $evaluator) {
            $out[] = $evaluator->facets();
        }

        return $out;
    }
}
