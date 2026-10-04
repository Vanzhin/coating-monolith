<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfileIdsByPositions;

use App\Shared\Application\Query\Query;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

final readonly class GetProfileIdsByPositionsQuery extends Query
{
    public function __construct(public StringCollection $positionIds)
    {
    }
}
