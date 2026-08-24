<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;

/**
 * Доменный сервис-оценщик с патронажем хендлеров по стандартам. Принимает систему,
 * прогоняет её через все зарегистрированные StandardEvaluator и собирает общий список
 * соответствий по всем стандартам. Сам про конкретные стандарты не знает — знание заперто
 * в хендлерах. Зовётся write-стороной (проектором) при мутации системы; агрегат его не знает.
 */
final readonly class SystemComplianceEvaluator
{
    /**
     * @param iterable<StandardEvaluator> $evaluators
     */
    public function __construct(private iterable $evaluators)
    {
    }

    /**
     * @return list<Compliance> всё, чему система соответствует, по всем стандартам
     */
    public function evaluate(CoatingSystem $system): array
    {
        $result = [];
        foreach ($this->evaluators as $evaluator) {
            foreach ($evaluator->evaluate($system) as $compliance) {
                $result[] = $compliance;
            }
        }

        return $result;
    }
}
