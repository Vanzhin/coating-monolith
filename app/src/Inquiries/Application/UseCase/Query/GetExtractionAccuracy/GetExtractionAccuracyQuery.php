<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetExtractionAccuracy;

use App\Shared\Application\Query\Query;

/**
 * Градусник точности извлечения для дашборда очереди: эталон = одобренные запросы, лимит — сколько
 * последних учесть. Модель не зовётся (сравнение сохранённого робота с одобренным).
 */
final readonly class GetExtractionAccuracyQuery extends Query
{
    public function __construct(public int $limit = 500)
    {
    }
}
