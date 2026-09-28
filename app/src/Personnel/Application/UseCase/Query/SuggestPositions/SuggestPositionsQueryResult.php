<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\SuggestPositions;

use App\Personnel\Application\DTO\Position\PositionDTO;

final readonly class SuggestPositionsQueryResult
{
    /**
     * @param list<PositionDTO> $positions
     */
    public function __construct(public array $positions)
    {
    }
}
