<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\RemoveCoatingSystem;

use App\Shared\Application\Command\Command;

final readonly class RemoveCoatingSystemCommand extends Command
{
    public function __construct(
        public string $id,
    ) {
    }
}
