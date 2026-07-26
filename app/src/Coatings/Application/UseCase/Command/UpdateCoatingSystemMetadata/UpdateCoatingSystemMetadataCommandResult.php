<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateCoatingSystemMetadata;

final class UpdateCoatingSystemMetadataCommandResult
{
    public function __construct(
        public ?string $id,
    ) {
    }
}
