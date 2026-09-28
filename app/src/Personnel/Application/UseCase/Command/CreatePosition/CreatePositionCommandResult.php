<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\CreatePosition;

final readonly class CreatePositionCommandResult
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
