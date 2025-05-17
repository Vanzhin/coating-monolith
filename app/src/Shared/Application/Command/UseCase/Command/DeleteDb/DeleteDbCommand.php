<?php

declare(strict_types=1);

namespace App\Shared\Application\Command\UseCase\Command\DeleteDb;

use App\Shared\Application\Command\Command;

readonly class DeleteDbCommand extends Command
{
    public function __construct(public string $dbTitle)
    {
    }
}
