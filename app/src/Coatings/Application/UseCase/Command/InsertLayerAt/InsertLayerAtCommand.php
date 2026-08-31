<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\InsertLayerAt;

use App\Shared\Application\Command\Command;

final readonly class InsertLayerAtCommand extends Command
{
    public function __construct(
        public string $systemId,
        public int $position,
        public string $coatingId,
        public int $dft,
        public string $colorId,
    ) {
    }
}
