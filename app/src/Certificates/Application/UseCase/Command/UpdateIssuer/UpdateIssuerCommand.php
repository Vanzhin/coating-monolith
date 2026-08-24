<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\UpdateIssuer;

use App\Shared\Application\Command\Command;

final readonly class UpdateIssuerCommand extends Command
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
