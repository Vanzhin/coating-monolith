<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\MoveLayer;

use App\Shared\Application\Command\Command;

final readonly class MoveLayerCommand extends Command
{
    public function __construct(
        public string $systemId,
        public int $from,
        public int $to,
    ) {
    }
}
