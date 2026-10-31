<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

/**
 * Дашборд точности извлечения для очереди запросов: строки по осям (слабые сверху, с подписями)
 * + общее число одобренных запросов и сколько из них легло в эталон.
 */
final readonly class ExtractionAccuracyView
{
    /** @param list<AxisAccuracyRow> $rows */
    public function __construct(
        public array $rows,
        public int $inquiriesScored,
        public int $inquiriesApproved,
    ) {
    }
}
