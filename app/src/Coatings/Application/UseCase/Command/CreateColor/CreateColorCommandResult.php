<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateColor;

final readonly class CreateColorCommandResult
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $ral,
        public string $hex,
    ) {
    }
}
