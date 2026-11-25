<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Infrastructure\Repository;

use App\Shared\Application\Security\AccessGuard;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Shared\Domain\Security\AuthUserInterface;
use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000000;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000001;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000002;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000003;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000004;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000005;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000006;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000007;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000008;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000009;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000010;
use App\Shared\Infrastructure\Database\Migrations\Version20261122000000;
use App\Shared\Infrastructure\Database\Migrations\Version20261122000001;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\DTO\TripInput;
use App\Waybills\Application\Service\AccessControl\LocationAccessControl;
use App\Waybills\Application\Service\AccessControl\WaybillAccessControl;
use App\Waybills\Application\Service\LocationCatalog;
use App\Waybills\Application\Service\RefuelingOperationBuilder;
use App\Waybills\Application\Service\WaybillRouteBuilder;
use App\Waybills\Application\UseCase\Command\UpdateWaybill\UpdateWaybillCommand;
use App\Waybills\Application\UseCase\Command\UpdateWaybill\UpdateWaybillCommandHandler;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Vehicle\FuelOperation;
use App\Waybills\Domain\Aggregate\Vehicle\FuelOperationType;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\RefuelingOperations;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use App\Waybills\Infrastructure\Repository\LocationRepository;
use App\Waybills\Infrastructure\Repository\VehicleRepository;
use App\Waybills\Infrastructure\Repository\WaybillRepository;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** Две сессии в отдельной схеме тестовой БД; к рабочей БД не подключается. */
final class WaybillConcurrencyTest extends KernelTestCase
{
    private Connection $first;
    private Connection $second;
    private EntityManager $firstManager;
    private EntityManager $secondManager;
    private string $schema;
    private bool $ownsSchema = false;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::bootKernel();
        $params = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->getParams();
        self::assertSame('test_db', $params['host'] ?? null);
        self::assertSame('app_test', $params['dbname'] ?? null);
        $this->first = DriverManager::getConnection($params);
        $this->second = DriverManager::getConnection($params);
        self::assertSame('app_test', $this->first->fetchOne('SELECT current_database()'));
        $this->schema = 'wb_test_'.str_replace('-', '', (string) Uuid::v4());
        $this->first->executeStatement('CREATE SCHEMA '.$this->schema);
        $this->ownsSchema = true;
        $this->first->executeStatement('SET search_path TO '.$this->schema);
        $this->second->executeStatement('SET search_path TO '.$this->schema);
        $this->second->executeStatement("SET lock_timeout = '100ms'");
        foreach ([Version20261121000000::class, Version20261121000001::class, Version20261121000002::class, Version20261121000003::class, Version20261121000004::class, Version20261121000005::class, Version20261121000006::class, Version20261121000007::class, Version20261121000008::class, Version20261121000009::class, Version20261121000010::class, Version20261122000000::class, Version20261122000001::class] as $migrationClass) {
            $this->applyMigration($migrationClass);
        }
        $config = static::getContainer()->get(EntityManagerInterface::class)->getConfiguration();
        $this->firstManager = new EntityManager($this->first, $config);
        $this->secondManager = new EntityManager($this->second, $config);
        $this->vehicle = new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2025-01-01'), new Location('База'), 100, BigDecimal::of(30));
        $driver = new DriverProfile(Uuid::v7(), 'owner', new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), new Location('База'));
        $this->vehicle->enter($driver);
        $this->firstManager->persist($driver);
        $this->firstManager->persist($this->vehicle);
        $this->firstManager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->firstManager)) {
            $this->firstManager->close();
        }
        if (isset($this->secondManager)) {
            $this->secondManager->close();
        }
        if (isset($this->second)) {
            if ($this->second->isTransactionActive()) {
                $this->second->rollBack();
            }
            $this->second->close();
        }
        if (isset($this->first)) {
            if ($this->first->isTransactionActive()) {
                $this->first->rollBack();
            }
            if ($this->ownsSchema) {
                $this->first->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');
            }
            $this->first->close();
        }
        parent::tearDown();
    }

    public function test_second_creation_waits_for_vehicle_then_refuses_a_second_draft(): void
    {
        $firstRepo = new WaybillRepository($this->firstManager, $this->first);
        $secondRepo = new WaybillRepository($this->secondManager, $this->second);
        $secondVehicle = $this->secondManager->find(Vehicle::class, $this->vehicle->getId());
        self::assertInstanceOf(Vehicle::class, $secondVehicle);
        $firstDraft = $this->draft($this->vehicle, 1);
        $secondDraft = $this->draft($secondVehicle, 2);

        $this->first->beginTransaction();
        $firstRepo->add($firstDraft);
        try {
            $secondRepo->add($secondDraft);
            self::fail('Второе соединение должно ждать блокировку автомобиля.');
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState());
        }
        $this->first->commit();
        try {
            $secondRepo->add($secondDraft);
            self::fail('Второй черновик автомобиля должен быть отклонён.');
        } catch (AppException $exception) {
            self::assertStringContainsString('черновик', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->first->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
        self::assertSame($firstDraft->getId(), $this->first->fetchOne('SELECT id FROM waybills_waybill'));
    }

    public function test_driver_exit_blocks_a_waiting_save_after_the_lock_is_released(): void
    {
        $firstRepo = new WaybillRepository($this->firstManager, $this->first);
        $draft = $this->draft($this->vehicle, 1);
        $firstRepo->add($draft);
        $secondRepo = new WaybillRepository($this->secondManager, $this->second);
        $stale = $secondRepo->findOneById($draft->getId());
        self::assertInstanceOf(Waybill::class, $stale);
        $trip = new RouteLog(Uuid::v7(), $stale->vehicle(), null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Назначение'), 1), RouteLogOrigin::Manual);
        $stale->setTrips($trip);

        $this->first->beginTransaction();
        $this->first->executeStatement('UPDATE waybills_vehicle SET driver_id = NULL WHERE id = ?', [$this->vehicle->getId()]);
        try {
            $secondRepo->add($stale);
            self::fail('Сохранение должно ждать смену водителя.');
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState());
        }
        $this->first->commit();
        try {
            $secondRepo->add($stale);
            self::fail('Без водителя сохранить лист нельзя.');
        } catch (AppException $exception) {
            self::assertStringContainsString('без водителя', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->first->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
        self::assertSame(1, (int) $this->first->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
    }

    public function test_number_allocation_waits_and_rolls_back(): void
    {
        $firstRepo = new WaybillRepository($this->firstManager, $this->first);
        $secondRepo = new WaybillRepository($this->secondManager, $this->second);
        $this->first->beginTransaction();
        self::assertSame(1, $firstRepo->nextNumber('owner'));
        $this->second->beginTransaction();
        try {
            $secondRepo->nextNumber('owner');
            self::fail('Второе выделение номера должно ждать первую транзакцию.');
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState());
        }
        $this->second->rollBack();
        $this->first->rollBack();
        $this->second->beginTransaction();
        self::assertSame(1, $secondRepo->nextNumber('owner'));
        $this->second->commit();
        self::assertSame(2, (int) $this->first->fetchOne("SELECT next_waybill_number FROM waybills_driver_profile WHERE owner_id = 'owner'"));
    }

    public function test_stale_edit_on_second_connection_does_not_replace_saved_trips(): void
    {
        $firstRepo = new WaybillRepository($this->firstManager, $this->first);
        $draft = $this->draft($this->vehicle, 1);
        $firstRepo->add($draft);
        $secondRepo = new WaybillRepository($this->secondManager, $this->second);
        $stale = $secondRepo->findOneById($draft->getId());
        self::assertInstanceOf(Waybill::class, $stale);
        $firstTrip = new RouteLog(Uuid::v7(), $this->vehicle, null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Первая точка'), 1), RouteLogOrigin::Manual);
        $draft->setTrips($firstTrip);
        $firstRepo->add($draft);
        $staleTrip = new RouteLog(Uuid::v7(), $stale->vehicle(), null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Другая точка'), 1), RouteLogOrigin::Manual);
        $stale->setTrips($staleTrip);

        try {
            $secondRepo->add($stale);
            self::fail('Устаревший лист не должен менять журнал.');
        } catch (AppException $exception) {
            self::assertStringContainsString('изменился', $exception->getMessage());
        }
        self::assertSame((string) $firstTrip->id, $this->first->fetchOne('SELECT id FROM waybills_route_log WHERE draft_waybill_id = ?', [$draft->getId()]));
        self::assertSame(1, (int) $this->first->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
        self::assertSame(2, (int) $this->first->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
    }

    public function test_trip_id_claimed_after_availability_check_rolls_back_new_draft(): void
    {
        $repository = new WaybillRepository($this->secondManager, $this->second);
        $id = Uuid::v7();
        self::assertFalse($repository->hasExistingTripIds(new StringCollection((string) $id)));

        $otherVehicle = new Vehicle(Uuid::v7(), 'Другой', new RegistrationNumber('B124CC77'), new \DateTimeImmutable('2025-01-01'), new Location('Другая база'), 0, BigDecimal::zero());
        $occupied = new RouteLog($id, $otherVehicle, null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Чужая точка'), 7, 'Чужое предприятие'), RouteLogOrigin::Manual);
        $this->first->beginTransaction();
        $this->firstManager->persist($otherVehicle);
        $this->firstManager->persist($occupied);
        $this->firstManager->flush();
        $this->first->commit();
        $original = $this->first->fetchAssociative('SELECT * FROM waybills_route_log WHERE id = ?', [(string) $id]);
        self::assertIsArray($original);

        $vehicle = $this->secondManager->find(Vehicle::class, $this->vehicle->getId());
        self::assertInstanceOf(Vehicle::class, $vehicle);
        $draft = $this->draft($vehicle, 1);
        $trip = new RouteLog($id, $vehicle, null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Своя точка'), 5), RouteLogOrigin::Manual);
        $draft->setTrips($trip);

        try {
            $repository->add($draft);
            self::fail('Занятый после проверки UUID должен отклонить сохранение.');
        } catch (AppException $exception) {
            self::assertSame('Поездка не принадлежит этому черновику.', $exception->getMessage());
        }

        self::assertSame(0, (int) $this->first->fetchOne('SELECT COUNT(*) FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
        self::assertSame(0, (int) $this->first->fetchOne('SELECT COUNT(*) FROM waybills_route_log WHERE draft_waybill_id = ?', [$draft->getId()]));
        self::assertSame(1, (int) $this->first->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
        self::assertSame($original, $this->first->fetchAssociative('SELECT * FROM waybills_route_log WHERE id = ?', [(string) $id]));
        self::assertSame(100, (int) $this->first->fetchOne('SELECT current_odometer FROM waybills_vehicle WHERE id = ?', [$vehicle->getId()]));
    }

    public function test_stale_edit_cannot_replace_or_delete_saved_refuelings(): void
    {
        $firstRepo = new WaybillRepository($this->firstManager, $this->first);
        $draft = $this->draft($this->vehicle, 1);
        $id = Uuid::v7();
        $date = new \DateTimeImmutable('2026-01-10 12:00:00');
        $initial = new FuelOperation($id, $this->vehicle, $date, FuelType::Ai95, BigDecimal::of('1'), FuelOperationType::Refueling);
        $draft->reviseDraftWithRefuelingOperations($draft->period(), null, new Fuel((string) $draft->fuelStart(), 25, new Refuelings(), 'АИ-95'), null, new RefuelingOperations($initial));
        $firstRepo->add($draft);
        $secondRepo = new WaybillRepository($this->secondManager, $this->second);
        $stale = $secondRepo->findOneById($draft->getId());
        self::assertInstanceOf(Waybill::class, $stale);

        $updated = new FuelOperation($id, $this->vehicle, $date, FuelType::Ai95, BigDecimal::of('2.00000000000000000001'), FuelOperationType::Refueling);
        $added = new FuelOperation(Uuid::v7(), $this->vehicle, $date, FuelType::Ai95, BigDecimal::of('3'), FuelOperationType::Refueling);
        $draft->reviseDraftWithRefuelingOperations($draft->period(), null, $draft->fuel(), null, new RefuelingOperations($updated, $added));
        $firstRepo->add($draft);
        $stale->reviseDraftWithRefuelingOperations($stale->period(), null, $stale->fuel(), null, new RefuelingOperations());
        try {
            $secondRepo->add($stale);
            self::fail('Устаревший лист не должен удалять заправки.');
        } catch (AppException $exception) {
            self::assertStringContainsString('изменился', $exception->getMessage());
        }
        self::assertSame('2.00000000000000000001', $this->first->fetchOne('SELECT amount::text FROM waybills_fuel_operation WHERE id = ?', [(string) $id]));
        self::assertSame(2, (int) $this->first->fetchOne('SELECT COUNT(*) FROM waybills_fuel_operation WHERE draft_waybill_id = ?', [$draft->getId()]));
        self::assertSame(2, (int) $this->first->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
    }

    public function test_driver_change_rechecks_authorization_after_the_vehicle_lock(): void
    {
        $newDriver = new DriverProfile(Uuid::v7(), 'other', new DriverDetails('Новый водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), new Location('База'));
        $this->firstManager->persist($newDriver);
        $this->firstManager->flush();
        $draft = $this->draft($this->vehicle, 1);
        (new WaybillRepository($this->firstManager, $this->first))->add($draft);
        $registry = new class($this->secondManager, $this->second) extends AbstractManagerRegistry {
            public function __construct(private EntityManager $em, private Connection $connection)
            {
                parent::__construct('test', ['default' => 'connection'], ['default' => 'manager'], 'default', 'default');
            }

            protected function getService(string $name): object
            {
                return 'manager' === $name ? $this->em : $this->connection;
            }

            protected function resetService(string $name): void
            {
            }
        };
        $fetcher = new class implements AuthUserFetcherInterface {
            public function getAuthUser(): AuthUserInterface
            {
                throw new \LogicException('В тесте используется только идентификатор.');
            }

            public function getAuthUserId(): string
            {
                return 'owner';
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
        $access = new WaybillAccessControl(static::getContainer()->get(AccessGuard::class), $fetcher);
        $locations = new LocationCatalog(new LocationRepository($registry), new LocationAccessControl(static::getContainer()->get(AccessGuard::class), $fetcher));
        $repository = new WaybillRepository($this->secondManager, $this->second);
        $handler = new UpdateWaybillCommandHandler($repository, new VehicleRepository($registry), $access, new WaybillRouteBuilder($repository), new RefuelingOperationBuilder(), $locations);
        $period = WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
        $command = new UpdateWaybillCommand($draft->getId(), 1, $period, null, null, true, [], null, new TripInput(null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Назначение'), 1)));

        $this->first->beginTransaction();
        $this->first->executeStatement('UPDATE waybills_vehicle SET driver_id = ? WHERE id = ?', [$newDriver->getId(), $this->vehicle->getId()]);
        try {
            $this->second->transactional(fn () => $handler($command));
            self::fail('Сохранение должно ждать смену водителя.');
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState());
        }
        $this->first->commit();
        try {
            $this->second->transactional(fn () => $handler($command));
            self::fail('Прежний водитель больше не может редактировать лист.');
        } catch (ForbiddenException) {
            self::assertSame(0, (int) $this->first->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
        }
        self::assertSame(1, (int) $this->first->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
    }

    public function test_second_approval_cannot_update_vehicle_and_journal_twice(): void
    {
        $firstRepo = new WaybillRepository($this->firstManager, $this->first);
        $trip = new RouteLog(Uuid::v7(), $this->vehicle, null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Назначение'), 5), RouteLogOrigin::Manual);
        $draft = $this->draft($this->vehicle, 1);
        $draft->revise($draft->period(), 105, new Fuel(30, 25, new Refuelings(), 'АИ-95'), new Route($this->vehicle, $trip, $trip));
        $firstRepo->add($draft);
        $secondRepo = new WaybillRepository($this->secondManager, $this->second);
        $stale = $secondRepo->findOneById($draft->getId());
        self::assertInstanceOf(Waybill::class, $stale);

        $firstRepo->finalize($draft);
        try {
            $secondRepo->finalize($stale);
            self::fail('Повторное утверждение устаревшей копии должно быть отклонено.');
        } catch (AppException $exception) {
            self::assertStringContainsString('изменился', $exception->getMessage());
        }

        self::assertSame(105, $this->firstManager->find(Vehicle::class, $this->vehicle->getId())->currentOdometer());
        self::assertSame('25', (string) $this->firstManager->find(Vehicle::class, $this->vehicle->getId())->fuelRemaining());
        self::assertSame(1, (int) $this->first->fetchOne("SELECT COUNT(*) FROM waybills_fuel_operation WHERE type = 'reconciliation'"));
        self::assertSame(1, (int) $this->first->fetchOne('SELECT COUNT(*) FROM waybills_route_log WHERE draft_waybill_id IS NULL'));
    }

    public function test_deletion_waits_for_vehicle_lock_and_removes_only_the_draft(): void
    {
        $firstRepo = new WaybillRepository($this->firstManager, $this->first);
        $draft = $this->draft($this->vehicle, 1);
        $firstRepo->add($draft);
        $secondRepo = new WaybillRepository($this->secondManager, $this->second);
        $loaded = $secondRepo->findOneById($draft->getId());
        self::assertInstanceOf(Waybill::class, $loaded);

        $this->first->beginTransaction();
        $this->first->fetchOne('SELECT id FROM waybills_vehicle WHERE id = ? FOR UPDATE', [$this->vehicle->getId()]);
        try {
            $secondRepo->remove($loaded);
            self::fail('Удаление должно ждать блокировку автомобиля.');
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState());
        }
        $this->first->commit();
        $secondRepo->remove($loaded);

        self::assertSame(0, (int) $this->first->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
        self::assertSame(1, (int) $this->first->fetchOne('SELECT COUNT(*) FROM waybills_vehicle'));
    }

    private function draft(Vehicle $vehicle, int $number): Waybill
    {
        return Waybill::createDraft(Uuid::v7(), $number, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
    }

    /** @param class-string<AbstractMigration> $migrationClass */
    private function applyMigration(string $migrationClass): void
    {
        $migration = new $migrationClass($this->first, new NullLogger());
        $migration->up(new Schema());
        $this->first->beginTransaction();
        try {
            foreach ($migration->getSql() as $query) {
                $this->first->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            $this->first->commit();
        } catch (\Throwable $exception) {
            $this->first->rollBack();
            throw $exception;
        }
    }
}
