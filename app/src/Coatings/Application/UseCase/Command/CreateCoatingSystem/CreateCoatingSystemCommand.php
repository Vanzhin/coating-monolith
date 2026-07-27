<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateCoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Shared\Application\Command\Command;

final readonly class CreateCoatingSystemCommand extends Command
{
    /**
     * @param array<int, array{coatingId: string, dft: int}> $initialLayers
     */
    public function __construct(
        public string $title,
        public string $description,
        public Substrate $substrate,
        public string $surfaceTreatmentId,
        public array $initialLayers = [],
    ) {
    }
}
