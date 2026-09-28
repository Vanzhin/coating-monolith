<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetPagedPositions;

use App\Personnel\Application\DTO\Position\PositionDTO;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedPositionsQueryResult
{
    /**
     * @param list<PositionDTO> $positions
     */
    public function __construct(
        public array $positions,
        public Pager $pager,
    ) {
    }
}
