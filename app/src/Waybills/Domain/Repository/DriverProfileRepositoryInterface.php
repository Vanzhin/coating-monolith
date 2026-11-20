<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Repository;

use App\Shared\Domain\Repository\PaginationResult;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;

interface DriverProfileRepositoryInterface
{
    public function findOneById(string $id): ?DriverProfile;

    public function findByFilter(ProfileFilter $filter): PaginationResult;

    public function add(DriverProfile $profile): void;
}
