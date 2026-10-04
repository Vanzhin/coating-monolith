<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfile;

use App\Shared\Application\Query\Query;

final readonly class GetProfileQuery extends Query
{
    public function __construct(public string $id)
    {
    }
}
