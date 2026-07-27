<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment;

final class CreateSurfaceTreatmentCommandResult
{
    public function __construct(public readonly string $id)
    {
    }
}
