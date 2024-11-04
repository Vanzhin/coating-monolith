<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Shared\Domain\Repository\PaginationResult;

interface ManufacturerRepositoryInterface
{
    public function add(Manufacturer $manufacturer): void;

    public function findOneByTitle(string $title): ?Manufacturer;

    public function findByFilter(ManufacturersFilter $filter): PaginationResult;


}