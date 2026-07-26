<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\RemoveLayerAt;

final readonly class RemoveLayerAtCommand
{
    public function __construct(
        public string $systemId,
        public int $position,
    ) {
    }
}
