<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateCoatingSystem;

final class CreateCoatingSystemCommandResult
{
    public function __construct(
        public string $id,
    ) {
    }
}
