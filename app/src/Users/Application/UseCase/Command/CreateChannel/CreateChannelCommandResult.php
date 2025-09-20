<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\CreateChannel;

class CreateChannelCommandResult
{
    public function __construct(
        public string $id,
    )
    {
    }
}
