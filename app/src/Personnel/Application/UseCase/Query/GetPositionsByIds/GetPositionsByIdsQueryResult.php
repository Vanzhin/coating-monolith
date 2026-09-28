<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetPositionsByIds;

use App\Personnel\Application\DTO\Position\PositionDTO;

final readonly class GetPositionsByIdsQueryResult
{
    /**
     * @param list<PositionDTO> $positions
     */
    public function __construct(public array $positions)
    {
    }
}
