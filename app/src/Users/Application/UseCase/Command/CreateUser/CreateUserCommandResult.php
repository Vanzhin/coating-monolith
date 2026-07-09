<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\CreateUser;

class CreateUserCommandResult
{
    public function __construct(
        public string $ulid,
    ) {
    }
}
