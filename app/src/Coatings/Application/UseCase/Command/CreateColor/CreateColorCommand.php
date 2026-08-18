<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateColor;

use App\Shared\Application\Command\Command;

final readonly class CreateColorCommand extends Command
{
    public function __construct(
        public string $name,
        public ?string $ral = null,
        public ?string $hex = null,
    ) {
    }
}
