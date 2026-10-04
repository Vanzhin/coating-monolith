<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetRequirement;

use App\Compliance\Application\DTO\Requirement\RequirementDTO;

final readonly class GetRequirementQueryResult
{
    public function __construct(public ?RequirementDTO $requirement)
    {
    }
}
