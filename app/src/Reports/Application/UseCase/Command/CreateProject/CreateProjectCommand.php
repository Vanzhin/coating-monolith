<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\CreateProject;

use App\Shared\Application\Command\Command;

final readonly class CreateProjectCommand extends Command
{
    public function __construct(
        public string $title,
        public string $counterpartyId,
        public ?string $description = null,
    ) {
    }
}
