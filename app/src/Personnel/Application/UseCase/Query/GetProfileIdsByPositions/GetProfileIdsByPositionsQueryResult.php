<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfileIdsByPositions;

final readonly class GetProfileIdsByPositionsQueryResult
{
    /**
     * @param list<string> $profileIds
     */
    public function __construct(public array $profileIds)
    {
    }
}
