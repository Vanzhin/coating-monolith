<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\InsertLayerAt;

final class InsertLayerAtCommandResult
{
    public function __construct(
        public readonly ?string $layerId,
    ) {
    }
}
