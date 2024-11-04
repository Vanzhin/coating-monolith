<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\CreateManufacturer;

class CreateManufacturerCommandResult
{
    public function __construct(
        public string $id,
    )
    {
    }
}
