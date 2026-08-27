<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\CreateIssuer;

final readonly class CreateIssuerCommandResult
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
