<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateGeneralTag;

final readonly class CreateGeneralTagCommandResult
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
