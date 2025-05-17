<?php

declare(strict_types=1);


namespace App\Shared\Application\Command\UseCase\Command\InitDb;

use App\Shared\Application\Command\Command;

readonly class InitDbCommand extends Command
{
    public function __construct(public string $dbTitle)
    {
    }
}
