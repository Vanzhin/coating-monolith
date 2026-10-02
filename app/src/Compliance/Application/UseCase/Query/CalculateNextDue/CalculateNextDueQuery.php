<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\CalculateNextDue;

use App\Shared\Application\Query\Query;

/** Следующий срок от даты выдачи по периодичности — расчёт на бэке (домен), для подсказки в форме. */
readonly class CalculateNextDueQuery extends Query
{
    public function __construct(
        public string $date,
        public string $cadenceKind,
        public ?int $number = null,
        public ?string $unit = null,
    ) {
    }
}
