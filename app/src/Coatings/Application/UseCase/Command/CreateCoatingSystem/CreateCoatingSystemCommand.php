<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateCoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Shared\Application\Command\Command;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

final readonly class CreateCoatingSystemCommand extends Command
{
    /**
     * @param array<int, array{coatingId: string, dft: int, colorId?: ?string}> $initialLayers
     */
    public function __construct(
        public string $title,
        public string $description,
        public Substrate $substrate,
        public EnvironmentType $environment,
        public string $surfaceTreatmentId,
        public array $initialLayers = [],
        public StringCollection $tagIds = new StringCollection(),
    ) {
    }
}
