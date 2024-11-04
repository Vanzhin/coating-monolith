<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetPagedManufacturers;

use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use App\Shared\Domain\Repository\Pager;

class GetPagedManufacturersQueryResult
{
    /**
     * @param ManufacturerDTO[] $manufacturers
     */
    public function __construct(public readonly array $manufacturers, public readonly Pager $pager)
    {
    }
}
