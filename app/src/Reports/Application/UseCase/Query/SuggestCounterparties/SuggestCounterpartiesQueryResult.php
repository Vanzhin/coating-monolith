<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\SuggestCounterparties;

use App\Reports\Application\DTO\Counterparties\CounterpartyDTO;

final readonly class SuggestCounterpartiesQueryResult
{
    /**
     * @param list<CounterpartyDTO> $counterparties
     */
    public function __construct(public array $counterparties)
    {
    }
}
