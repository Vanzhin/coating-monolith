<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SaveRequirement;

final readonly class SaveRequirementCommandResult
{
    public function __construct(public string $id)
    {
    }
}
