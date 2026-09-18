<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetPagedCounterparties;

use App\Reports\Application\DTO\Counterparties\CounterpartyDTO;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedCounterpartiesQueryResult
{
    /**
     * @param list<CounterpartyDTO> $counterparties
     */
    public function __construct(
        public array $counterparties,
        public Pager $pager,
    ) {
    }
}
