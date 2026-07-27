<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateLayerDft;

use App\Shared\Application\Command\Command;

final readonly class UpdateLayerDftCommand extends Command
{
    public function __construct(
        public string $systemId,
        public int $position,
        public int $dft,
    ) {
    }
}
