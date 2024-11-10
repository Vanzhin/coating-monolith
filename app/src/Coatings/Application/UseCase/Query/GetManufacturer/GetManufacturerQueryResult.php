<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetManufacturer;

use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;

readonly class GetManufacturerQueryResult
{
    public function __construct(public ?ManufacturerDTO $manufacturer)
    {
    }
}
