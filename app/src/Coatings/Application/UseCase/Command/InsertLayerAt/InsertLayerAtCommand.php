<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\InsertLayerAt;

final readonly class InsertLayerAtCommand
{
    public function __construct(
        public string $systemId,
        public int $position,
        public string $coatingId,
        public int $dft,
    ) {
    }
}
