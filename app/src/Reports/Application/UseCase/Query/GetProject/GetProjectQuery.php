<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetProject;

use App\Shared\Application\Query\Query;

final readonly class GetProjectQuery extends Query
{
    public function __construct(public string $id)
    {
    }
}
