<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateLayerDft;

final readonly class UpdateLayerDftCommand
{
    public function __construct(
        public string $systemId,
        public int $position,
        public int $dft,
    ) {
    }
}
