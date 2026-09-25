<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\UpdateCounterparty;

use App\Shared\Application\Command\Command;

final readonly class UpdateCounterpartyCommand extends Command
{
    public function __construct(
        public string $id,
        public string $title,
        public string $tin,
        public ?string $description = null,
    ) {
    }
}
