<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\AppendLayer;

use App\Shared\Application\Command\Command;

final readonly class AppendLayerCommand extends Command
{
    public function __construct(
        public string $systemId,
        public string $coatingId,
        public int $dft,
    ) {
    }
}
