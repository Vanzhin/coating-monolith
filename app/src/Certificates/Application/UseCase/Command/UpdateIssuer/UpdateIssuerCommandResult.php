<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\UpdateIssuer;

final readonly class UpdateIssuerCommandResult
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
