<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\CreateUser;

use App\Shared\Application\Command\Command;

readonly class CreateUserCommand extends Command
{
    public function __construct(
        public string $email,
        public ?string $password,
    ) {
    }
}
