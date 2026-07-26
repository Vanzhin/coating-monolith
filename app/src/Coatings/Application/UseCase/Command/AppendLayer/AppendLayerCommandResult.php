<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\AppendLayer;

final class AppendLayerCommandResult
{
    public function __construct(
        public readonly ?string $layerId,
    ) {
    }
}
