<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Repository;

use App\Shared\Domain\Repository\PaginationResult;
use App\Shared\Infrastructure\Database\DBAL\ExactDateTimeType;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Vehicle\FuelOperation;
use App\Waybills\Domain\Aggregate\Vehicle\RefuelingOperations;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use App\Waybills\Domain\Repository\WaybillFilter;
use App\Waybills\Domain\Repository\WaybillRepositoryInterface;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class WaybillRepository implements WaybillRepositoryInterface
{
    /** @var \WeakMap<Waybill, int> */
    private \WeakMap $versions;

    public function __construct(private EntityManagerInterface $em, private Connection $connection)
    {
        $this->versions = new \WeakMap();
    }

    public function add(Waybill $waybill): void
    {
        $waybill->assertEditable();
        $waybill->assertOdometerMatchesTrips();
        $waybill->assertSavedRefuelingsAreValid();
        $trips = $waybill->trips();
        $refuelings = $waybill->refuelingOperations()->all();
        $flushed = false;
        try {
            $version = $this->connection->transactional(function () use ($waybill, $trips, $refuelings, &$flushed): int {
                if ('read committed' !== $this->connection->fetchOne('SHOW transaction_isolation')) {
                    throw new \LogicException('Сохранение путевого листа требует изоляции READ COMMITTED.');
                }

                $this->assertNoPendingVehicleChanges($waybill->vehicle());
                $vehicle = $this->em->find(Vehicle::class, $waybill->vehicleId(), LockMode::PESSIMISTIC_WRITE)
                    ?? throw new AppException('Автомобиль путевого листа не найден.');
                $waybill->assertCanSave($vehicle);
                $old = $this->connection->fetchAssociative('SELECT vehicle_id, locked, version FROM waybills_waybill WHERE id = ? FOR UPDATE', [$waybill->getId()]);
                $expectedVersion = $this->versions[$waybill] ?? null;
                if ((false === $old && null !== $expectedVersion)
                    || (false !== $old && ($expectedVersion !== (int) $old['version'] || $old['vehicle_id'] !== $waybill->vehicleId() || $this->toBool($old['locked'])))) {
                    throw new AppException('Путевой лист изменился. Обновите данные и повторите операцию.');
                }
                if (false === $old) {
                    $waybill->assertInitialReadingsMatch($vehicle);
                }
                $this->assertPeriodsAvailable($waybill);
                [$draftIds, $predecessor] = $this->readDraftBoundary($waybill);
                $this->assertDraftContinuation($waybill, $predecessor, ...$trips);
                $this->assertNoPendingTripChanges($draftIds, ...$trips);
                $refuelingIds = $this->connection->fetchFirstColumn('SELECT id FROM waybills_fuel_operation WHERE vehicle_id = ? AND draft_waybill_id = ?', [$waybill->vehicleId(), $waybill->getId()]);
                $this->assertNoPendingRefuelingChanges($refuelingIds, ...$refuelings);

                $this->connection->executeStatement('SET CONSTRAINTS uniq_waybills_route_log_previous, fk_waybills_route_log_previous, fk_waybills_route_log_draft, uniq_waybills_fuel_operation_draft_position, fk_waybills_fuel_operation_draft DEFERRED');
                $this->em->persist($waybill);
                $this->em->flush();
                $flushed = true;
                $this->syncDraftTrips($waybill, $draftIds, ...$trips);
                $this->syncDraftRefuelings($waybill, $refuelingIds, ...$refuelings);
                $version = false === $old ? 1 : (int) $old['version'] + 1;
                if (false !== $old) {
                    $this->connection->executeStatement('UPDATE waybills_waybill SET version = ? WHERE id = ?', [$version, $waybill->getId()]);
                }
                $this->connection->executeStatement('SET CONSTRAINTS uniq_waybills_route_log_previous, fk_waybills_route_log_previous, fk_waybills_route_log_draft, uniq_waybills_fuel_operation_draft_position, fk_waybills_fuel_operation_draft IMMEDIATE');
                $this->detachTrips($draftIds, ...$trips);
                $this->detachRefuelings($refuelingIds, ...$refuelings);

                return $version;
            });
        } catch (\Throwable $exception) {
            // После отката flush больше не соответствует данным БД.
            if ($flushed) {
                $this->em->close();
            }
            throw $exception;
        }
        $this->versions[$waybill] = $version;
    }

    public function finalize(Waybill $waybill): bool
    {
        $waybill->assertEditable();
        $flushed = false;
        try {
            $version = $this->connection->transactional(function () use ($waybill, &$flushed): int {
                if ('read committed' !== $this->connection->fetchOne('SHOW transaction_isolation')) {
                    throw new \LogicException('Утверждение путевого листа требует изоляции READ COMMITTED.');
                }

                $this->assertNoPendingVehicleChanges($waybill->vehicle());
                $vehicle = $this->em->find(Vehicle::class, $waybill->vehicleId(), LockMode::PESSIMISTIC_WRITE)
                    ?? throw new AppException('Автомобиль путевого листа не найден.');
                $waybill->assertCanSave($vehicle);
                $old = $this->connection->fetchAssociative('SELECT vehicle_id, locked, version FROM waybills_waybill WHERE id = ? FOR UPDATE', [$waybill->getId()]);
                $expectedVersion = $this->versions[$waybill] ?? null;
                if (false === $old || null === $expectedVersion || $expectedVersion !== (int) $old['version']
                    || $old['vehicle_id'] !== $waybill->vehicleId() || $this->toBool($old['locked'])) {
                    throw new AppException('Путевой лист изменился. Обновите данные и повторите операцию.');
                }

                $waybill->assertInitialReadingsMatch($vehicle);
                $this->assertPeriodsAvailable($waybill);
                [$draftIds, $predecessor] = $this->readDraftBoundary($waybill);
                $trips = $waybill->trips();
                $this->assertDraftContinuation($waybill, $predecessor, ...$trips);
                $this->assertNoPendingTripChanges($draftIds, ...$trips);
                $refuelingIds = $this->connection->fetchFirstColumn('SELECT id FROM waybills_fuel_operation WHERE vehicle_id = ? AND draft_waybill_id = ?', [$waybill->vehicleId(), $waybill->getId()]);
                $refuelings = $waybill->refuelingOperations()->all();
                $this->assertNoPendingRefuelingChanges($refuelingIds, ...$refuelings);
                $waybill->assertSavedRefuelingsAreValid();

                $lastApprovedLog = null;
                if (null !== $predecessor) {
                    $lastApprovedLog = $this->em->find(RouteLog::class, (string) $predecessor['id']);
                    if (!$lastApprovedLog instanceof RouteLog) {
                        throw new \LogicException('Не удалось загрузить последнюю утверждённую поездку.');
                    }
                }
                $reconciliation = $waybill->approve(Uuid::v7(), $vehicle, ...$refuelings);
                $vehicle->approveRoute($waybill->route(), $lastApprovedLog, BigDecimal::of((string) $waybill->fuel()->remaining()));

                $this->em->persist($waybill);
                $this->em->persist($reconciliation);
                $this->em->persist($vehicle);
                $flushed = true;
                $this->em->flush();
                $this->connection->executeStatement('UPDATE waybills_route_log SET draft_waybill_id = NULL WHERE vehicle_id = ? AND draft_waybill_id = ?', [$waybill->vehicleId(), $waybill->getId()]);
                $this->connection->executeStatement('UPDATE waybills_fuel_operation SET draft_waybill_id = NULL WHERE vehicle_id = ? AND draft_waybill_id = ?', [$waybill->vehicleId(), $waybill->getId()]);
                $version = (int) $old['version'] + 1;
                $this->connection->executeStatement('UPDATE waybills_waybill SET version = ? WHERE id = ?', [$version, $waybill->getId()]);

                return $version;
            });
        } catch (\Throwable $exception) {
            if ($flushed) {
                $this->em->close();
            }
            throw $exception;
        }
        $this->versions[$waybill] = $version;

        return true;
    }

    public function remove(Waybill $waybill): void
    {
        $waybill->assertEditable();
        $this->connection->transactional(function () use ($waybill): void {
            if ('read committed' !== $this->connection->fetchOne('SHOW transaction_isolation')) {
                throw new \LogicException('Удаление путевого листа требует изоляции READ COMMITTED.');
            }

            $this->assertNoPendingVehicleChanges($waybill->vehicle());
            $vehicle = $this->em->find(Vehicle::class, $waybill->vehicleId(), LockMode::PESSIMISTIC_WRITE)
                ?? throw new AppException('Автомобиль путевого листа не найден.');
            $waybill->assertCanSave($vehicle);
            $old = $this->connection->fetchAssociative('SELECT vehicle_id, locked, version FROM waybills_waybill WHERE id = ? FOR UPDATE', [$waybill->getId()]);
            $expectedVersion = $this->versions[$waybill] ?? null;
            if (false === $old || null === $expectedVersion || $expectedVersion !== (int) $old['version']
                || $old['vehicle_id'] !== $waybill->vehicleId() || $this->toBool($old['locked'])) {
                throw new AppException('Путевой лист изменился. Обновите данные и повторите операцию.');
            }

            $tripIds = $this->connection->fetchFirstColumn('SELECT id FROM waybills_route_log WHERE vehicle_id = ? AND draft_waybill_id = ?', [$waybill->vehicleId(), $waybill->getId()]);
            $refuelingIds = $this->connection->fetchFirstColumn('SELECT id FROM waybills_fuel_operation WHERE vehicle_id = ? AND draft_waybill_id = ?', [$waybill->vehicleId(), $waybill->getId()]);
            $this->assertNoPendingTripChanges($tripIds, ...$waybill->trips());
            $this->assertNoPendingRefuelingChanges($refuelingIds, ...$waybill->refuelingOperations()->all());
            $this->connection->executeStatement('DELETE FROM waybills_route_log WHERE vehicle_id = ? AND draft_waybill_id = ?', [$waybill->vehicleId(), $waybill->getId()]);
            $this->connection->executeStatement('DELETE FROM waybills_fuel_operation WHERE vehicle_id = ? AND draft_waybill_id = ?', [$waybill->vehicleId(), $waybill->getId()]);
            $deleted = $this->connection->executeStatement('DELETE FROM waybills_waybill WHERE id = ? AND vehicle_id = ? AND locked = FALSE', [$waybill->getId(), $waybill->vehicleId()]);
            if (1 !== $deleted) {
                throw new AppException('Путевой лист изменился. Обновите данные и повторите операцию.');
            }
            $this->detachTrips($tripIds, ...$waybill->trips());
            $this->detachRefuelings($refuelingIds, ...$waybill->refuelingOperations()->all());
            $this->em->detach($waybill);
        });
        unset($this->versions[$waybill]);
    }

    public function nextNumber(string $driverOwnerId): int
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Номер путевого листа выделяется только в транзакции создания.');
        }
        $number = $this->connection->fetchOne(<<<'SQL'
            UPDATE waybills_driver_profile
            SET next_waybill_number = next_waybill_number + 1
            WHERE owner_id = ? AND next_waybill_number <= 2147483647
            RETURNING next_waybill_number - 1
            SQL, [$driverOwnerId]);
        if (false === $number) {
            throw new AppException('Не удалось выделить номер путевого листа. Проверьте карточку водителя.');
        }

        return (int) $number;
    }

    public function findOneById(string $id): ?Waybill
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $managed = $this->em->getUnitOfWork()->tryGetById(['id' => Uuid::fromString($id)], Waybill::class);
        if ($managed instanceof Waybill && !isset($this->versions[$managed])) {
            throw new \LogicException('Путевой лист уже загружен вне репозитория; версия и поездки неизвестны.');
        }
        $version = $this->connection->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$id]);
        if (false === $version) {
            return null;
        }
        $waybill = $this->em->find(Waybill::class, $id);
        if (null === $waybill || isset($this->versions[$waybill])) {
            return $waybill;
        }

        if ($waybill->isLocked()) {
            $ids = $this->connection->fetchFirstColumn(<<<'SQL'
                SELECT id FROM waybills_route_log
                WHERE vehicle_id = ? AND draft_waybill_id IS NULL
                  AND split_part(occurred_at, '|', 1)::timestamptz >= ?::timestamptz
                  AND split_part(occurred_at, '|', 1)::timestamptz <= ?::timestamptz
                SQL, [$waybill->vehicleId(), $waybill->period()->from()->format('Y-m-d H:i:s.uP'), $waybill->period()->to()->format('Y-m-d H:i:s.uP')]);
        } else {
            $ids = $this->connection->fetchFirstColumn('SELECT id FROM waybills_route_log WHERE vehicle_id = ? AND draft_waybill_id = ?', [$waybill->vehicleId(), $id]);
        }
        $trips = [] === $ids ? [] : $this->em->createQuery('SELECT trip, predecessor FROM '.RouteLog::class.' trip LEFT JOIN trip.previous predecessor WHERE trip.id IN (:ids)')
            ->setParameter('ids', $ids)->getResult();
        if ($waybill->isLocked()) {
            $refuelingIds = $this->connection->fetchFirstColumn(<<<'SQL'
                SELECT id FROM waybills_fuel_operation
                WHERE vehicle_id = ? AND type = 'refueling' AND draft_waybill_id IS NULL
                  AND split_part(occurred_at, '|', 1)::timestamptz >= ?::timestamptz
                  AND split_part(occurred_at, '|', 1)::timestamptz <= ?::timestamptz
                ORDER BY split_part(occurred_at, '|', 1)::timestamptz, id
                SQL, [$waybill->vehicleId(), $waybill->period()->from()->format('Y-m-d H:i:s.uP'), $waybill->period()->to()->format('Y-m-d H:i:s.uP')]);
        } else {
            $refuelingIds = $this->connection->fetchFirstColumn('SELECT id FROM waybills_fuel_operation WHERE vehicle_id = ? AND draft_waybill_id = ? ORDER BY position, id', [$waybill->vehicleId(), $id]);
        }
        $refuelings = [] === $refuelingIds ? [] : $this->em->createQuery('SELECT operation FROM '.FuelOperation::class.' operation WHERE operation.id IN (:ids)')
            ->setParameter('ids', $refuelingIds)->getResult();
        $refuelingsById = [];
        foreach ($refuelings as $operation) {
            $refuelingsById[(string) $operation->id] = $operation;
        }
        $orderedRefuelings = [];
        foreach ($refuelingIds as $refuelingId) {
            $orderedRefuelings[] = $refuelingsById[$refuelingId] ?? throw new \LogicException('Не удалось загрузить заправку путевого листа.');
        }
        if ((int) $version !== (int) $this->connection->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$id])) {
            throw new AppException('Путевой лист изменился. Обновите данные и повторите операцию.');
        }
        $waybill->restoreTrips(...$trips);
        $waybill->restoreRefuelingOperations(new RefuelingOperations(...$orderedRefuelings));
        $this->versions[$waybill] = (int) $version;

        return $waybill;
    }

    public function findByFilter(WaybillFilter $filter): PaginationResult
    {
        $where = [];
        $params = [];
        if (null !== $filter->driverOwnerId) {
            $where[] = 'EXISTS (SELECT 1 FROM waybills_vehicle v JOIN waybills_driver_profile p ON p.id = v.driver_id WHERE v.id = waybills_waybill.vehicle_id AND p.owner_id = :driver_owner)';
            $params['driver_owner'] = $filter->driverOwnerId;
        }
        if (null !== $filter->vehicleId) {
            if (!Uuid::isValid($filter->vehicleId)) {
                return new PaginationResult([], 0);
            }
            $where[] = 'vehicle_id = :vehicle';
            $params['vehicle'] = $filter->vehicleId;
        }
        $condition = [] === $where ? '' : ' WHERE '.implode(' AND ', $where);
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_waybill'.$condition, $params);
        $query = $this->connection->createQueryBuilder()->select('id')->from('waybills_waybill')
            ->orderBy('SUBSTRING(period_from, 1, 10)', 'DESC')->addOrderBy('id', 'ASC')
            ->setFirstResult($filter->pager->getOffset())->setMaxResults($filter->pager->getLimit());
        foreach ($where as $clause) {
            $query->andWhere($clause);
        }
        $query->setParameters($params);
        $items = [];
        foreach ($query->executeQuery()->fetchFirstColumn() as $id) {
            $waybill = $this->findOneById((string) $id);
            if (null !== $waybill) {
                $items[] = $waybill;
            }
        }

        return new PaginationResult($items, $total);
    }

    public function findPredecessor(Waybill $waybill): ?RouteLog
    {
        [, $predecessor] = $this->readDraftBoundary($waybill);
        if (null === $predecessor) {
            return null;
        }

        return $this->em->find(RouteLog::class, (string) $predecessor['id']);
    }

    public function versionOf(Waybill $waybill): int
    {
        return $this->versions[$waybill] ?? throw new \LogicException('Версия путевого листа не загружена.');
    }

    private function assertPeriodsAvailable(Waybill $waybill): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT period_from, period_to, locked FROM waybills_waybill WHERE vehicle_id = ? AND id <> ?', [$waybill->vehicleId(), $waybill->getId()]);
        $periods = [];
        $approved = [];
        $hasOtherDraft = false;
        foreach ($rows as $row) {
            $period = WaybillPeriod::fromDates(ExactDateTimeType::parse((string) $row['period_from']), ExactDateTimeType::parse((string) $row['period_to']));
            $periods[] = $period;
            if ($this->toBool($row['locked'])) {
                $approved[] = $period;
            } else {
                $hasOtherDraft = true;
            }
        }
        $waybill->assertVehicleHasNoOtherDraft($hasOtherDraft);
        $waybill->period()->assertDoesNotOverlap(...$periods);
        $waybill->period()->assertFollows(...$approved);
    }

    /** @return array{list<string>, array<string, mixed>|null} */
    private function readDraftBoundary(Waybill $waybill): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, previous_id, draft_waybill_id, occurred_at, location FROM waybills_route_log WHERE vehicle_id = ?', [$waybill->vehicleId()]);
        $next = [];
        foreach ($rows as $row) {
            $key = $row['previous_id'] ?? '';
            if (isset($next[$key])) {
                throw new AppException('Журнал поездок автомобиля содержит разветвление.');
            }
            $next[$key] = $row;
        }
        $current = $next[''] ?? null;
        $visited = 0;
        $draftIds = [];
        $predecessor = null;
        while (null !== $current && $visited < count($rows)) {
            ++$visited;
            if (null === $current['draft_waybill_id']) {
                if ([] !== $draftIds) {
                    throw new AppException('Изменение затрагивает утверждённую историю поездок.');
                }
                $predecessor = $current;
            } elseif ($current['draft_waybill_id'] === $waybill->getId()) {
                $draftIds[] = (string) $current['id'];
            } else {
                throw new AppException('Журнал содержит поездки другого черновика.');
            }
            $current = $next[$current['id']] ?? null;
        }
        if ($visited !== count($rows) || null !== $current) {
            throw new AppException('Журнал поездок автомобиля содержит разрыв или цикл.');
        }

        return [$draftIds, $predecessor];
    }

    /** @param array<string, mixed>|null $predecessor */
    private function assertDraftContinuation(Waybill $waybill, ?array $predecessor, RouteLog ...$trips): void
    {
        if ([] === $trips) {
            return;
        }
        $previous = $trips[0]->previous;
        if (($predecessor['id'] ?? null) !== (null === $previous ? null : (string) $previous->id)) {
            throw new AppException('Черновик должен продолжать журнал поездок автомобиля.');
        }
        if (null !== $predecessor && null !== $previous) {
            $location = Location::fromArray(json_decode((string) $predecessor['location'], true, flags: JSON_THROW_ON_ERROR));
            if (!$location->samePlace($previous->destination->location) || ExactDateTimeType::parse((string) $predecessor['occurred_at']) != $previous->date) {
                throw new AppException('Предыдущая поездка изменилась. Обновите данные и повторите операцию.');
            }
        }
    }

    /** @param list<string> $draftIds */
    private function syncDraftTrips(Waybill $waybill, array $draftIds, RouteLog ...$trips): void
    {
        $existing = array_fill_keys($draftIds, true);
        $newIds = [];
        foreach ($trips as $trip) {
            $id = (string) $trip->id;
            $newIds[] = $id;
            $values = [
                'previous_id' => null === $trip->previous ? null : (string) $trip->previous->id,
                'occurred_at' => ExactDateTimeType::format($trip->date),
                'location' => json_encode($trip->destination->location, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'enterprise' => $trip->destination->enterprise,
                'distance' => $trip->distanceInKilometers(),
                'origin' => $trip->origin->value,
            ];
            if (isset($existing[$id])) {
                $count = $this->connection->update('waybills_route_log', $values, ['id' => $id, 'vehicle_id' => $waybill->vehicleId(), 'draft_waybill_id' => $waybill->getId()]);
                if (1 !== $count) {
                    throw new AppException('Поездка изменилась. Обновите данные и повторите операцию.');
                }
            } else {
                $this->connection->insert('waybills_route_log', ['id' => $id, 'vehicle_id' => $waybill->vehicleId(), 'draft_waybill_id' => $waybill->getId(), ...$values]);
            }
        }
        $removed = array_values(array_diff($draftIds, $newIds));
        if ([] !== $removed) {
            $this->connection->executeStatement('DELETE FROM waybills_route_log WHERE vehicle_id = ? AND draft_waybill_id = ? AND id IN (?)', [$waybill->vehicleId(), $waybill->getId(), $removed], [Types::STRING, Types::STRING, ArrayParameterType::STRING]);
        }
    }

    /** @param list<string> $draftIds */
    private function syncDraftRefuelings(Waybill $waybill, array $draftIds, FuelOperation ...$refuelings): void
    {
        $existing = array_fill_keys($draftIds, true);
        $newIds = [];
        foreach ($refuelings as $position => $operation) {
            $id = (string) $operation->id;
            $newIds[] = $id;
            $values = [
                'occurred_at' => ExactDateTimeType::format($operation->date),
                'fuel_type' => $operation->fuelType->value,
                'amount' => (string) $operation->change,
                'type' => $operation->type->value,
                'waybill_id' => $operation->waybill?->getId(),
                'position' => $position,
            ];
            if (isset($existing[$id])) {
                $count = $this->connection->update('waybills_fuel_operation', $values, ['id' => $id, 'vehicle_id' => $waybill->vehicleId(), 'draft_waybill_id' => $waybill->getId()]);
                if (1 !== $count) {
                    throw new AppException('Заправка изменилась. Обновите данные и повторите операцию.');
                }
            } else {
                $this->connection->insert('waybills_fuel_operation', ['id' => $id, 'vehicle_id' => $waybill->vehicleId(), 'draft_waybill_id' => $waybill->getId(), ...$values]);
            }
        }
        $removed = array_values(array_diff($draftIds, $newIds));
        if ([] !== $removed) {
            $this->connection->executeStatement('DELETE FROM waybills_fuel_operation WHERE vehicle_id = ? AND draft_waybill_id = ? AND id IN (?)', [$waybill->vehicleId(), $waybill->getId(), $removed], [Types::STRING, Types::STRING, ArrayParameterType::STRING]);
        }
    }

    private function assertNoPendingVehicleChanges(Vehicle $vehicle): void
    {
        if (!$this->em->contains($vehicle)) {
            return;
        }
        $uow = $this->em->getUnitOfWork();
        $uow->computeChangeSet($this->em->getClassMetadata(Vehicle::class), $vehicle);
        if ([] !== $uow->getEntityChangeSet($vehicle)) {
            throw new \LogicException('Нельзя сохранять лист одновременно с несохранёнными изменениями автомобиля.');
        }
    }

    /** @param list<string> $draftIds */
    private function assertNoPendingTripChanges(array $draftIds, RouteLog ...$trips): void
    {
        $ids = array_fill_keys([...$draftIds, ...array_map(static fn (RouteLog $trip): string => (string) $trip->id, $trips)], true);
        $uow = $this->em->getUnitOfWork();
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates(), ...$uow->getScheduledEntityDeletions()] as $entity) {
            if ($entity instanceof RouteLog && isset($ids[(string) $entity->id])) {
                throw new \LogicException('Поездка уже ожидает сохранения через Doctrine.');
            }
        }
    }

    /** @param list<string> $draftIds */
    private function assertNoPendingRefuelingChanges(array $draftIds, FuelOperation ...$refuelings): void
    {
        $ids = array_fill_keys([...$draftIds, ...array_map(static fn (FuelOperation $operation): string => (string) $operation->id, $refuelings)], true);
        $uow = $this->em->getUnitOfWork();
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates(), ...$uow->getScheduledEntityDeletions()] as $entity) {
            if ($entity instanceof FuelOperation && isset($ids[(string) $entity->id])) {
                throw new \LogicException('Заправка уже ожидает сохранения через Doctrine.');
            }
        }
    }

    /** @param list<string> $draftIds */
    private function detachTrips(array $draftIds, RouteLog ...$trips): void
    {
        $ids = array_fill_keys([...$draftIds, ...array_map(static fn (RouteLog $trip): string => (string) $trip->id, $trips)], true);
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[RouteLog::class] ?? [] as $trip) {
            if (isset($ids[(string) $trip->id])) {
                $this->em->detach($trip);
            }
        }
    }

    /** @param list<string> $draftIds */
    private function detachRefuelings(array $draftIds, FuelOperation ...$refuelings): void
    {
        $ids = array_fill_keys([...$draftIds, ...array_map(static fn (FuelOperation $operation): string => (string) $operation->id, $refuelings)], true);
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[FuelOperation::class] ?? [] as $operation) {
            if (isset($ids[(string) $operation->id])) {
                $this->em->detach($operation);
            }
        }
    }

    private function toBool(mixed $value): bool
    {
        return (bool) $this->connection->convertToPHPValue($value, Types::BOOLEAN);
    }
}
