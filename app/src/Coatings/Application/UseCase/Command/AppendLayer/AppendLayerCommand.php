<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\AppendLayer;

final readonly class AppendLayerCommand
{
    public function __construct(
        public string $systemId,
        public string $coatingId,
        public int $dft,
    ) {
    }
}
