<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\MoveLayer;

final readonly class MoveLayerCommand
{
    public function __construct(
        public string $systemId,
        public int $from,
        public int $to,
    ) {
    }
}
