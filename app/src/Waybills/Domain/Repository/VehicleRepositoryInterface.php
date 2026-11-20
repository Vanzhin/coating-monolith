<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Repository;

use App\Shared\Domain\Repository\PaginationResult;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;

interface VehicleRepositoryInterface
{
    public function add(Vehicle $vehicle): void;

    /** Блокировка для обновления требует активной транзакции. */
    public function findOneById(string $id, bool $forUpdate = false): ?Vehicle;

    public function findByFilter(VehicleFilter $filter): PaginationResult;
}
