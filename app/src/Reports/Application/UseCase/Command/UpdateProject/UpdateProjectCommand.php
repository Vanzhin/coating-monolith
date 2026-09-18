<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\UpdateProject;

use App\Shared\Application\Command\Command;

final readonly class UpdateProjectCommand extends Command
{
    public function __construct(
        public string $id,
        public string $title,
        public string $counterpartyId,
        public ?string $description = null,
    ) {
    }
}
