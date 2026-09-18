<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\CreateProject;

final readonly class CreateProjectCommandResult
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
