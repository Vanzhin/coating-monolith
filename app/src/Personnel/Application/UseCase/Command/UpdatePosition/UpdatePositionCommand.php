<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\UpdatePosition;

use App\Shared\Application\Command\Command;

final readonly class UpdatePositionCommand extends Command
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
