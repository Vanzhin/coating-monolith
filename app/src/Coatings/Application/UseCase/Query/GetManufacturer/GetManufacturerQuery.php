<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetManufacturer;

use App\Shared\Application\Query\Query;

readonly class GetManufacturerQuery extends Query
{
    public function __construct(public string $manufacturerId)
    {
    }
}
