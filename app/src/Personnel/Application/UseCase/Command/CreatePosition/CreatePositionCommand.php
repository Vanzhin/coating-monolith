<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\CreatePosition;

use App\Shared\Application\Command\Command;

final readonly class CreatePositionCommand extends Command
{
    public function __construct(public string $title)
    {
    }
}
