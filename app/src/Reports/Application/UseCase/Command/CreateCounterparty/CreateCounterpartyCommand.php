<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\CreateCounterparty;

use App\Shared\Application\Command\Command;

final readonly class CreateCounterpartyCommand extends Command
{
    public function __construct(
        public string $title,
        public string $tin,
        public ?string $description = null,
    ) {
    }
}
