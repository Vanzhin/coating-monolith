<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetPosition;

use App\Personnel\Application\DTO\Position\PositionDTO;

final readonly class GetPositionQueryResult
{
    public function __construct(public ?PositionDTO $position)
    {
    }
}
