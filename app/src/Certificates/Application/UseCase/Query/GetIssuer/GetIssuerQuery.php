<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\GetIssuer;

use App\Shared\Application\Query\Query;

final readonly class GetIssuerQuery extends Query
{
    public function __construct(public string $id)
    {
    }
}
