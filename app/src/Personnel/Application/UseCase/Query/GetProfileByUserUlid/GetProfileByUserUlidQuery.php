<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfileByUserUlid;

use App\Shared\Application\Query\Query;

final readonly class GetProfileByUserUlidQuery extends Query
{
    public function __construct(public string $userUlid)
    {
    }
}
