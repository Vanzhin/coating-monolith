<?php

declare(strict_types=1);

namespace App\Shared\Application\Command\UseCase\Command\InitDb;

readonly class InitDbCommandResult
{
    public function __construct(public bool $isCreated)
    {
    }
}
