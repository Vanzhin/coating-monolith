<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\UpdateCounterparty;

final readonly class UpdateCounterpartyCommandResult
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
