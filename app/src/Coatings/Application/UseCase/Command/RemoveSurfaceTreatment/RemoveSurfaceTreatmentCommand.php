<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\RemoveSurfaceTreatment;

use App\Shared\Application\Command\Command;

final readonly class RemoveSurfaceTreatmentCommand extends Command
{
    public function __construct(
        public string $id,
    ) {
    }
}
