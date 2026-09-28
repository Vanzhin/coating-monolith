<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetPagedPositions;

use App\Personnel\Domain\Repository\PositionsFilter;
use App\Shared\Application\Query\Query;

final readonly class GetPagedPositionsQuery extends Query
{
    public function __construct(public PositionsFilter $filter)
    {
    }
}
