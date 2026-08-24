<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\CreateIssuer;

use App\Shared\Application\Command\Command;

final readonly class CreateIssuerCommand extends Command
{
    public function __construct(public string $title)
    {
    }
}
