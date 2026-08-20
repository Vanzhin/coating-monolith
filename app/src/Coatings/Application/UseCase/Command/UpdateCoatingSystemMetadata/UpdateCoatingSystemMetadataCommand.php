<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateCoatingSystemMetadata;

use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Shared\Application\Command\Command;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

final readonly class UpdateCoatingSystemMetadataCommand extends Command
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public Substrate $substrate,
        public EnvironmentType $environment,
        public string $surfaceTreatmentId,
        public StringCollection $tagIds = new StringCollection(),
    ) {
    }
}
