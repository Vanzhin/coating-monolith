<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Repository;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
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

    /** Проверяет UUID поездок во всём журнале, независимо от автомобиля и статуса. */
    public function hasExistingTripIds(StringCollection $ids): bool;

    public function versionOf(Waybill $waybill): int;

    /** Возвращает поездку, предшествующую черновику или новому листу автомобиля. */
    public function findPredecessor(Waybill|Vehicle $source): ?RouteLog;

    public function findByFilter(WaybillFilter $filter): PaginationResult;
}
