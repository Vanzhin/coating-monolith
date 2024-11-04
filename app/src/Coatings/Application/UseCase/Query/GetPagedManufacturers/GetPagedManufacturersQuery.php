<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetPagedManufacturers;

use App\Coatings\Domain\Repository\ManufacturersFilter;
use App\Shared\Application\Query\Query;

readonly class GetPagedManufacturersQuery extends Query
{
    public function __construct(public ManufacturersFilter $filter)
    {
    }
}
