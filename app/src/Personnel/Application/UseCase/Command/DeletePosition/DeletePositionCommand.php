<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\DeletePosition;

use App\Shared\Application\Command\Command;

final readonly class DeletePositionCommand extends Command
{
    public function __construct(public string $id)
    {
    }
}
