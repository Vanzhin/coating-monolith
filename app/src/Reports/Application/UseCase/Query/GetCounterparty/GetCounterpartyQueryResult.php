<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetCounterparty;

use App\Reports\Application\DTO\Counterparties\CounterpartyDTO;

final readonly class GetCounterpartyQueryResult
{
    public function __construct(public ?CounterpartyDTO $counterparty)
    {
    }
}
