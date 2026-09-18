<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\DeleteProject;

use App\Shared\Application\Command\Command;

final readonly class DeleteProjectCommand extends Command
{
    public function __construct(public string $id)
    {
    }
}
