<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Repository;

use App\Shared\Domain\Repository\PaginationResult;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;

interface WaybillRepositoryInterface
{
    public function add(Waybill $waybill): void;

    /** Выделяет номер внутри транзакции создания листа. */
    public function nextNumber(string $driverOwnerId): int;

    /** Утверждает лист, переводит журналы и обновляет состояние автомобиля в одной транзакции. */
    public function finalize(Waybill $waybill): bool;

    /** Удаляет черновик и только его поездки и заправки в одной транзакции. */
    public function remove(Waybill $waybill): void;

    public function findOneById(string $id): ?Waybill;

    public function versionOf(Waybill $waybill): int;

    /** Возвращает непосредственную предшествующую поездку автомобиля. */
    public function findPredecessor(Waybill $waybill): ?RouteLog;

    public function findByFilter(WaybillFilter $filter): PaginationResult;
}
