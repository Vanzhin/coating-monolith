<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\UpdatePosition;

final readonly class UpdatePositionCommandResult
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
