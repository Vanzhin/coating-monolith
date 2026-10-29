<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

/**
 * Дашборд точности извлечения для очереди запросов: строки по осям (слабые сверху, с подписями)
 * + сколько одобренных запросов легло в эталон.
 */
final readonly class ExtractionAccuracyView
{
    /** @param list<AxisAccuracyRow> $rows */
    public function __construct(
        public array $rows,
        public int $inquiriesScored,
    ) {
    }
}
