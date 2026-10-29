<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Service\Eval;

/**
 * Итог прогона эталона: точность по осям (слабые сверху) + сколько одобренных запросов учтено.
 * Эталон = одобренные запросы; измерение без вызова модели — сравниваем сохранённого робота с
 * тем, что в итоге одобрено.
 */
final readonly class ExtractionEvalReport
{
    /** @param list<AxisAccuracy> $axes отсортированы по точности по возрастанию (слабые первыми) */
    public function __construct(
        public array $axes,
        public int $inquiriesScored,
    ) {
    }
}
