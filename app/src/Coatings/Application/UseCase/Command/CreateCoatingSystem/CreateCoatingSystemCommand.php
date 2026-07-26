<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateCoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\CoatingSystem\SurfacePreparation;

final readonly class CreateCoatingSystemCommand
{
    /**
     * @param array<int, array{coatingId: string, dft: int}> $initialLayers
     */
    public function __construct(
        public string $title,
        public string $description,
        public Substrate $substrate,
        public SurfacePreparation $surfacePreparation,
        public array $initialLayers = [],
    ) {
    }
}
