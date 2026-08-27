<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\DeleteIssuer;

use App\Shared\Application\Command\Command;

final readonly class DeleteIssuerCommand extends Command
{
    public function __construct(public string $id)
    {
    }
}
