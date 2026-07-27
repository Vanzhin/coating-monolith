<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\RemoveLayerAt;

use App\Shared\Application\Command\Command;

final readonly class RemoveLayerAtCommand extends Command
{
    public function __construct(
        public string $systemId,
        public int $position,
    ) {
    }
}
