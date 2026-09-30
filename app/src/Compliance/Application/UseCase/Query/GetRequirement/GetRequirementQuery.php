<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetRequirement;

use App\Shared\Application\Query\Query;

readonly class GetRequirementQuery extends Query
{
    public function __construct(public string $id)
    {
    }
}
