<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\CalculateNextDue;

final readonly class CalculateNextDueQueryResult
{
    /** @param ?string $nextDue дата в формате Y-m-d или null (срока нет) */
    public function __construct(public ?string $nextDue)
    {
    }
}
