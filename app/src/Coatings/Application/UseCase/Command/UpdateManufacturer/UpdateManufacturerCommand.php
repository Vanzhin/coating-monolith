<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\UpdateManufacturer;

use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use App\Shared\Application\Command\Command;

readonly class UpdateManufacturerCommand extends Command
{
    public function __construct(
        public string          $manufacturerId,
        public ManufacturerDTO $manufacturerDTO,
    )
    {
    }
}
