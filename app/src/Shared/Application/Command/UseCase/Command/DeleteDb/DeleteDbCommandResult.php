<?php

declare(strict_types=1);

namespace App\Shared\Application\Command\UseCase\Command\DeleteDb;

readonly class DeleteDbCommandResult
{
    public function __construct(public bool $isCreated)
    {
    }
}
