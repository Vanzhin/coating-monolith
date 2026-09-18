<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\UpdateProject;

final readonly class UpdateProjectCommandResult
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
