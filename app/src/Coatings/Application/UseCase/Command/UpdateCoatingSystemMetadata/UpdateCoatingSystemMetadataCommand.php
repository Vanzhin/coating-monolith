<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateCoatingSystemMetadata;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\CoatingSystem\SurfacePreparation;
use App\Shared\Application\Command\Command;

final readonly class UpdateCoatingSystemMetadataCommand extends Command
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
