<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;

/**
 * Оценщик соответствия системы покрытий одному стандарту. Знает правила своего стандарта,
 * читает физические факты системы и отдаёт список соответствий. Каждый реализующий класс
 * помечается DI-тегом app.standard_evaluator и автоматически попадает в патронаж
 * SystemComplianceEvaluator — подключить стандарт = добавить реализацию, фасад не трогается.
 */
interface StandardEvaluator
{
    public function supports(ComplianceStandard $standard): bool;

    /**
     * @return list<Compliance> чему система соответствует по этому стандарту
     */
    public function evaluate(CoatingSystem $system): array;
}
