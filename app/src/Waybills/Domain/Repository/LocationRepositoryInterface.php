<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Repository;

use App\Shared\Domain\Repository\PaginationResult;
use App\Waybills\Domain\Aggregate\Location\Location;

interface LocationRepositoryInterface
{
    public function findOneById(string $id): ?Location;

    public function findByFilter(LocationFilter $filter): PaginationResult;

    public function add(Location $location): void;
}
