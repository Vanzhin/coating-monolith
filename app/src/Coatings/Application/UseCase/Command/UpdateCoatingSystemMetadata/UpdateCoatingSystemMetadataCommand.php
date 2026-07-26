<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateCoatingSystemMetadata;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\CoatingSystem\SurfacePreparation;

final readonly class UpdateCoatingSystemMetadataCommand
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public Substrate $substrate,
        public SurfacePreparation $surfacePreparation,
    ) {
    }
}
