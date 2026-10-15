<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetObligationHistory;

use App\Compliance\Application\DTO\ObligationHistory\ObligationHistoryEventDTO;

final readonly class GetObligationHistoryQueryResult
{
    /** @param list<ObligationHistoryEventDTO> $events Хронологически (от старых к новым). */
    public function __construct(public array $events)
    {
    }
}
