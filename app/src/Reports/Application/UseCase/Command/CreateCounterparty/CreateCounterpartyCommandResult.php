<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\CreateCounterparty;

final readonly class CreateCounterpartyCommandResult
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $tin = null,
    ) {
    }
}
