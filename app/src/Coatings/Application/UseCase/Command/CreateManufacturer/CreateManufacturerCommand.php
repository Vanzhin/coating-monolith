<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\CreateManufacturer;

use App\Shared\Application\Command\Command;

readonly class CreateManufacturerCommand extends Command
{
    public function __construct(
        public string  $title,
        public ?string $description = null,
    )
    {
    }
}
