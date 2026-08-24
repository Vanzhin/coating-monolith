<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Compliance\Facet\StandardFacets;

/**
 * Оценщик соответствия системы покрытий одному стандарту. Знает правила своего стандарта,
 * читает физические факты системы и отдаёт список соответствий, а также самоописание осей
 * маркировки (facets) для показа и фильтра. Каждый реализующий класс помечается DI-тегом
 * app.standard_evaluator и автоматически попадает в патронаж SystemComplianceEvaluator и в реестр
 * фасетов — подключить стандарт = добавить реализацию, фасады не трогаются.
 */
interface StandardEvaluator
{
    public function supports(ComplianceStandard $standard): bool;

    /**
     * @return list<Compliance> чему система соответствует по этому стандарту
     */
    public function evaluate(CoatingSystem $system): array;

    public function facets(): StandardFacets;
}
