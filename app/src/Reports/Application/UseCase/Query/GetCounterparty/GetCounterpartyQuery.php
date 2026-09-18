<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetCounterparty;

use App\Shared\Application\Query\Query;

final readonly class GetCounterpartyQuery extends Query
{
    public function __construct(public string $id)
    {
    }
}
