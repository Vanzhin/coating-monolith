<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetPosition;

use App\Shared\Application\Query\Query;

final readonly class GetPositionQuery extends Query
{
    public function __construct(public string $id)
    {
    }
}
