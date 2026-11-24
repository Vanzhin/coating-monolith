<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Infrastructure\Database;

use App\Shared\Application\Security\AccessGuard;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
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
use App\Shared\Infrastructure\Database\Migrations\Version20261122000000;
use App\Shared\Infrastructure\Database\Migrations\Version20261122000001;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Application\DTO\TripInput;
use App\Waybills\Application\Service\AccessControl\LocationAccessControl;
use App\Waybills\Application\Service\AccessControl\WaybillAccessControl;
use App\Waybills\Application\Service\LocationCatalog;
use App\Waybills\Application\Service\RefuelingOperationBuilder;
use App\Waybills\Application\Service\WaybillRouteBuilder;
use App\Waybills\Application\UseCase\Command\CreateWaybill\CreateWaybillCommand;
use App\Waybills\Application\UseCase\Command\CreateWaybill\CreateWaybillCommandHandler;
use App\Waybills\Application\UseCase\Command\LockWaybill\LockWaybillCommand;
use App\Waybills\Application\UseCase\Command\LockWaybill\LockWaybillCommandHandler;
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
use App\Waybills\Domain\Repository\WaybillFilter;
use App\Waybills\Infrastructure\Repository\LocationRepository;
use App\Waybills\Infrastructure\Repository\VehicleRepository;
use App\Waybills\Infrastructure\Repository\WaybillRepository;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillOrmMappingTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManager $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $configured = static::getContainer()->get(EntityManagerInterface::class);
        $params = $configured->getConnection()->getParams();
        self::assertSame('test_db', $params['host'] ?? null);
        self::assertSame('app_test', $params['dbname'] ?? null);
        $this->connection = DriverManager::getConnection($params);
        $this->em = new EntityManager($this->connection, $configured->getConfiguration());

        foreach ([Version20261121000000::class, Version20261121000001::class, Version20261121000002::class, Version20261121000003::class, Version20261121000004::class, Version20261121000005::class, Version20261121000006::class, Version20261121000007::class, Version20261121000008::class, Version20261121000009::class, Version20261122000000::class, Version20261122000001::class] as $migrationClass) {
            $this->applyTemporaryMigration($migrationClass);
        }
    }

    protected function tearDown(): void
    {
        $this->em->close();
        $this->connection->close();
        parent::tearDown();
    }

    public function test_incomplete_draft_round_trip_preserves_live_vehicle_and_period(): void
    {
        [$vehicle, $driver] = $this->vehicle();
        $from = new \DateTimeImmutable('2026-01-01 08:15:30.123456', new \DateTimeZone('Europe/Berlin'));
        $to = new \DateTimeImmutable('2026-01-31 18:45:00.654321+05:45');
        $draft = Waybill::createDraft(Uuid::v7(), 17, $vehicle, new DateTimeInterval($from, $to));
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->persist($draft);
        $this->em->flush();
        $id = $draft->getId();
        $row = $this->connection->fetchAssociative('SELECT * FROM waybills_waybill WHERE id = ?', [$id]);
        self::assertIsArray($row);
        self::assertSame(100, (int) $row['odometer_end']);
        self::assertNull($row['fuel']);
        self::assertNull($row['approved_driver']);
        self::assertNull($row['approved_vehicle_model']);
        $this->em->clear();

        $loaded = $this->em->find(Waybill::class, $id);
        self::assertInstanceOf(Waybill::class, $loaded);
        self::assertSame(17, $loaded->getNumber());
        self::assertSame($vehicle->getId(), $loaded->vehicleId());
        self::assertSame(100, $loaded->odometerStart());
        self::assertSame('30.00000000000000000001', (string) $loaded->fuelStart());
        self::assertSame($from->format('U.uP'), $loaded->period()->from()->format('U.uP'));
        self::assertSame('Europe/Berlin', $loaded->period()->from()->getTimezone()->getName());
        self::assertSame($to->format('U.uP'), $loaded->period()->to()->format('U.uP'));
        self::assertSame($to->getTimezone()->getName(), $loaded->period()->to()->getTimezone()->getName());
        self::assertFalse($loaded->hasRoute());
        self::assertSame('Водитель', $loaded->driver()?->fullName);

        $loaded->vehicle()->rename('Изменённая модель');
        $loaded->vehicle()->driver()?->changeDriverDetails($this->details('Изменённый водитель'));
        self::assertSame('Изменённая модель', $loaded->vehicleModel());
        self::assertSame('Изменённый водитель', $loaded->driver()->fullName);
        $this->em->flush();
        $this->em->clear();
        $loaded = $this->em->find(Waybill::class, $id);
        self::assertInstanceOf(Waybill::class, $loaded);
        self::assertSame('Изменённая модель', $loaded->vehicleModel());
        self::assertSame('Изменённый водитель', $loaded->driver()->fullName);
    }

    public function test_repository_saves_and_reloads_incomplete_draft_and_rewrites_trip_chain(): void
    {
        [$vehicle, $driver] = $this->vehicle('30');
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->flush();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $first = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-10 10:00:00+00:00'), new Destination(new Location('Первая'), 2), RouteLogOrigin::Manual);
        $last = new RouteLog(Uuid::v7(), $vehicle, $first, new \DateTimeImmutable('2026-01-10 12:00:00+00:00'), new Destination(new Location('Последняя'), 3), RouteLogOrigin::Manual);
        $draft->setTrips($first, $last);
        $repository = new WaybillRepository($this->em, $this->connection);
        $repository->add($draft);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log WHERE draft_waybill_id = ?', [$draft->getId()]));
        $id = $draft->getId();
        $lastId = (string) $last->id;
        $this->em->clear();

        $repository = new WaybillRepository($this->em, $this->connection);
        $loaded = $repository->findOneById($id);
        self::assertInstanceOf(Waybill::class, $loaded);
        self::assertSame([(string) $first->id, $lastId], array_map(static fn (RouteLog $trip): string => (string) $trip->id, $loaded->trips()));
        $loadedFirst = $loaded->trips()[0];
        $oldLast = $loaded->trips()[1];
        $loadedDriver = $loaded->vehicle()->driver();
        self::assertNotNull($loadedDriver);
        $loadedDriver->changeDriverDetails($this->details('Несохранённое изменение'));
        $out = new RouteLog(Uuid::v7(), $loaded->vehicle(), $loadedFirst, new \DateTimeImmutable('2026-01-10 11:00:00+00:00'), new Destination(new Location('Промежуточная'), 1), RouteLogOrigin::Manual);
        $back = new RouteLog(Uuid::v7(), $loaded->vehicle(), $out, new \DateTimeImmutable('2026-01-10 11:00:00+00:00'), new Destination(new Location('Первая'), 1), RouteLogOrigin::Manual);
        $route = $loaded->route()->insertAfter($loadedFirst, $out, $back);
        $loaded->setTrips(...iterator_to_array($route->logs(), false));
        $repository->add($loaded);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$id]));
        self::assertSame((string) $back->id, $this->connection->fetchOne('SELECT previous_id FROM waybills_route_log WHERE id = ?', [$lastId]));
        self::assertTrue($this->em->contains($loadedDriver));
        self::assertSame('Несохранённое изменение', $loadedDriver->getDriverDetails()->fullName);
        $reloadedLast = $this->em->find(RouteLog::class, Uuid::fromString($lastId));
        self::assertInstanceOf(RouteLog::class, $reloadedLast);
        self::assertNotSame($oldLast, $reloadedLast);
        self::assertSame((string) $back->id, (string) $reloadedLast->previous?->id);
        $this->em->clear();

        $loaded = (new WaybillRepository($this->em, $this->connection))->findOneById($id);
        self::assertInstanceOf(Waybill::class, $loaded);
        self::assertSame([(string) $first->id, (string) $out->id, (string) $back->id, $lastId], array_map(static fn (RouteLog $trip): string => (string) $trip->id, $loaded->trips()));
        self::assertSame(7, $loaded->route()->distance());
        self::assertSame('Несохранённое изменение', $loaded->driver()?->fullName);
        self::assertSame(107, (int) $this->connection->fetchOne('SELECT odometer_end FROM waybills_waybill WHERE id = ?', [$id]));
        self::assertNull($this->connection->fetchOne('SELECT fuel FROM waybills_waybill WHERE id = ?', [$id]));
    }

    public function test_repository_rejects_save_after_driver_leaves_without_losing_draft(): void
    {
        [$vehicle, $driver] = $this->vehicle();
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->flush();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $repository = new WaybillRepository($this->em, $this->connection);
        $repository->add($draft);
        $vehicle->leave($driver);
        $this->em->flush();

        try {
            $repository->add($draft);
            self::fail('Нельзя сохранить лист без водителя.');
        } catch (AppException $exception) {
            self::assertStringContainsString('без водителя', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
    }

    public function test_restored_inconsistent_draft_cannot_be_saved_until_corrected(): void
    {
        [$vehicle, $driver] = $this->vehicle();
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->flush();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        (new WaybillRepository($this->em, $this->connection))->add($draft);
        $id = $draft->getId();
        $this->connection->executeStatement('UPDATE waybills_waybill SET odometer_end = 105 WHERE id = ?', [$id]);
        $this->em->clear();

        $repository = new WaybillRepository($this->em, $this->connection);
        $loaded = $repository->findOneById($id);
        self::assertInstanceOf(Waybill::class, $loaded);
        try {
            $repository->add($loaded);
            self::fail('Нельзя сохранить несогласованное показание без поездок.');
        } catch (AppException $exception) {
            self::assertStringContainsString('должен совпадать', $exception->getMessage());
        }
        self::assertSame(105, (int) $this->connection->fetchOne('SELECT odometer_end FROM waybills_waybill WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$id]));

        $loaded->reviseDraft($loaded->period(), null, new Fuel((string) $loaded->fuelStart(), 25, new Refuelings(), 'АИ-95'), null);
        $repository->add($loaded);
        self::assertSame(100, (int) $this->connection->fetchOne('SELECT odometer_end FROM waybills_waybill WHERE id = ?', [$id]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$id]));
    }

    public function test_repository_rolls_back_waybill_if_trip_insert_fails(): void
    {
        [$vehicle, $driver] = $this->vehicle('30');
        $otherVehicle = new Vehicle(Uuid::v7(), 'Другой', new RegistrationNumber('B124CC77'), new \DateTimeImmutable('2025-01-01'), new Location('Другая база'), 0, BigDecimal::zero());
        $existingTrip = new RouteLog(Uuid::v7(), $otherVehicle, null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Назначение'), 1), RouteLogOrigin::Manual);
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->persist($otherVehicle);
        $this->em->persist($existingTrip);
        $this->em->flush();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $repository = new WaybillRepository($this->em, $this->connection);
        $repository->add($draft);
        $duplicate = new RouteLog($existingTrip->id, $vehicle, null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Назначение'), 1), RouteLogOrigin::Manual);
        $draft->setTrips($duplicate);

        try {
            $repository->add($draft);
            self::fail('Повторный ID поездки должен вызвать отказ.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
            self::assertFalse((bool) $this->connection->fetchOne('SELECT route_present FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log WHERE draft_waybill_id = ?', [$draft->getId()]));
            self::assertSame($otherVehicle->getId(), $this->connection->fetchOne('SELECT vehicle_id FROM waybills_route_log WHERE id = ?', [(string) $existingTrip->id]));
        }
    }

    public function test_repository_rejects_stale_draft_version(): void
    {
        [$vehicle, $driver] = $this->vehicle('30');
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->flush();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $repository = new WaybillRepository($this->em, $this->connection);
        $repository->add($draft);
        $this->connection->executeStatement('UPDATE waybills_waybill SET version = version + 1 WHERE id = ?', [$draft->getId()]);
        $trip = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Назначение'), 1), RouteLogOrigin::Manual);
        $draft->setTrips($trip);

        try {
            $repository->add($draft);
            self::fail('Устаревшую версию нельзя сохранить.');
        } catch (AppException $exception) {
            self::assertStringContainsString('изменился', $exception->getMessage());
        }
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log WHERE draft_waybill_id = ?', [$draft->getId()]));
    }

    public function test_repository_refuses_an_entity_loaded_without_its_version(): void
    {
        [$vehicle, $driver] = $this->vehicle();
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->flush();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        (new WaybillRepository($this->em, $this->connection))->add($draft);
        $id = $draft->getId();
        $this->em->clear();
        self::assertInstanceOf(Waybill::class, $this->em->find(Waybill::class, $id));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('версия и поездки неизвестны');
        (new WaybillRepository($this->em, $this->connection))->findOneById($id);
    }

    public function test_two_independent_entity_managers_cannot_overwrite_each_others_draft_trips(): void
    {
        [$vehicle, $driver] = $this->vehicle();
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->flush();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $firstRepository = new WaybillRepository($this->em, $this->connection);
        $firstRepository->add($draft);
        $secondManager = new EntityManager($this->connection, $this->em->getConfiguration());
        $secondRepository = new WaybillRepository($secondManager, $this->connection);
        $stale = $secondRepository->findOneById($draft->getId());
        self::assertInstanceOf(Waybill::class, $stale);
        $first = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Первая'), 1), RouteLogOrigin::Manual);
        $draft->setTrips($first);
        $firstRepository->add($draft);
        $other = new RouteLog(Uuid::v7(), $stale->vehicle(), null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Вторая'), 1), RouteLogOrigin::Manual);
        $stale->setTrips($other);

        try {
            $secondRepository->add($stale);
            self::fail('Устаревший черновик не должен перезаписывать поездку.');
        } catch (AppException $exception) {
            self::assertStringContainsString('изменился', $exception->getMessage());
        }
        self::assertSame((string) $first->id, $this->connection->fetchOne('SELECT id FROM waybills_route_log WHERE draft_waybill_id = ?', [$draft->getId()]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
        $secondManager->close();
    }

    public function test_draft_continues_approved_history_without_changing_it(): void
    {
        [$vehicle, $driver] = $this->vehicle('30');
        $previous = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2025-12-31 12:00:00+00:00'), new Destination(new Location('Предыдущая'), 4, 'История'), RouteLogOrigin::Manual);
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->persist($previous);
        $this->em->flush();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $trip = new RouteLog(Uuid::v7(), $vehicle, $previous, new \DateTimeImmutable('2026-01-10 12:00:00+00:00'), new Destination(new Location('Назначение'), 3), RouteLogOrigin::Manual);
        $draft->setTrips($trip);
        $repository = new WaybillRepository($this->em, $this->connection);
        $repository->add($draft);
        self::assertNull($this->connection->fetchOne('SELECT draft_waybill_id FROM waybills_route_log WHERE id = ?', [(string) $previous->id]));
        self::assertSame('История', $this->connection->fetchOne('SELECT enterprise FROM waybills_route_log WHERE id = ?', [(string) $previous->id]));
        $id = $draft->getId();
        $this->em->clear();

        $repository = new WaybillRepository($this->em, $this->connection);
        $loaded = $repository->findOneById($id);
        self::assertInstanceOf(Waybill::class, $loaded);
        self::assertSame((string) $previous->id, (string) $loaded->trips()[0]->previous?->id);
        self::assertSame((string) $previous->id, (string) $repository->findPredecessor($loaded)?->id);
        self::assertSame('Предыдущая', $loaded->trips()[0]->departure()->toString());
    }

    public function test_draft_filter_follows_current_driver_without_storing_owner_in_waybill(): void
    {
        [$vehicle, $firstDriver] = $this->vehicle();
        $secondDriver = new DriverProfile(Uuid::v7(), 'other-owner', $this->details('Другой водитель'), new Location('База'));
        $this->em->persist($firstDriver);
        $this->em->persist($secondDriver);
        $this->em->persist($vehicle);
        $this->em->flush();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $repository = new WaybillRepository($this->em, $this->connection);
        $repository->add($draft);
        self::assertSame(1, $repository->findByFilter(new WaybillFilter(driverOwnerId: 'owner'))->total);
        self::assertSame(0, $repository->findByFilter(new WaybillFilter(driverOwnerId: 'other-owner'))->total);

        $vehicle->leave($firstDriver);
        $this->em->flush();
        self::assertSame(0, $repository->findByFilter(new WaybillFilter(driverOwnerId: 'owner'))->total);
        self::assertNotNull($repository->findOneById($draft->getId()));
        $vehicle->enter($secondDriver);
        $this->em->flush();

        self::assertSame(0, $repository->findByFilter(new WaybillFilter(driverOwnerId: 'owner'))->total);
        $result = $repository->findByFilter(new WaybillFilter(driverOwnerId: 'other-owner'));
        self::assertSame(1, $result->total);
        self::assertSame($draft->getId(), $result->items[0]->getId());
        self::assertSame('Другой водитель', $draft->driver()?->fullName);
    }

    public function test_vehicle_cannot_have_a_second_draft_even_for_another_period(): void
    {
        [$vehicle, $driver] = $this->vehicle();
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->flush();
        $repository = new WaybillRepository($this->em, $this->connection);
        $first = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $second = Waybill::createDraft(Uuid::v7(), 2, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-28')));
        $repository->add($first);

        try {
            $repository->add($second);
            self::fail('У автомобиля не должно быть двух черновиков.');
        } catch (AppException $exception) {
            self::assertStringContainsString('черновик', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
        self::assertSame($first->getId(), $this->connection->fetchOne('SELECT id FROM waybills_waybill'));
    }

    public function test_draft_cannot_overlap_or_precede_approved_period(): void
    {
        [$vehicle, $driver] = $this->vehicle('30');
        $period = WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-10 23:59:59'));
        $trip = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-05'), new Destination(new Location('Назначение'), 3), RouteLogOrigin::Manual);
        $approved = Waybill::create(Uuid::v7(), 1, $vehicle, $period, 103, new Fuel(30, 25, new Refuelings(), 'АИ-95'), new Route($vehicle, $trip, $trip));
        $approved->lock();
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->persist($trip);
        $this->em->persist($approved);
        $this->em->flush();
        $repository = new WaybillRepository($this->em, $this->connection);
        foreach ([
            ['2026-01-10', '2026-01-20', 'пересекается'],
            ['2025-12-01', '2025-12-31', 'после последнего утверждённого'],
        ] as [$from, $to, $message]) {
            $draft = Waybill::createDraft(Uuid::v7(), 2, $vehicle, new DateTimeInterval(new \DateTimeImmutable($from), new \DateTimeImmutable($to)));
            try {
                $repository->add($draft);
                self::fail('Период не должен быть принят.');
            } catch (AppException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
    }

    public function test_draft_rejects_a_forged_predecessor_with_the_same_id(): void
    {
        [$vehicle, $driver] = $this->vehicle('30');
        $previous = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2025-12-31'), new Destination(new Location('Предыдущая'), 1), RouteLogOrigin::Manual);
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->persist($previous);
        $this->em->flush();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $forged = new RouteLog($previous->id, $vehicle, null, $previous->date, new Destination(new Location('Чужая точка'), 1), RouteLogOrigin::Manual);
        $trip = new RouteLog(Uuid::v7(), $vehicle, $forged, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Назначение'), 1), RouteLogOrigin::Manual);
        $draft->setTrips($trip);

        try {
            (new WaybillRepository($this->em, $this->connection))->add($draft);
            self::fail('Нельзя подменить данные предшествующей поездки.');
        } catch (AppException $exception) {
            self::assertStringContainsString('Предыдущая поездка изменилась', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
    }

    public function test_number_allocation_rolls_back_with_the_creation_transaction(): void
    {
        [$vehicle, $driver] = $this->vehicle();
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->flush();
        $repository = new WaybillRepository($this->em, $this->connection);

        $numbers = [];
        $this->connection->beginTransaction();
        $numbers[] = $repository->nextNumber('owner');
        $this->connection->rollBack();
        $this->connection->beginTransaction();
        $numbers[] = $repository->nextNumber('owner');
        $this->connection->commit();
        $this->connection->beginTransaction();
        $numbers[] = $repository->nextNumber('owner');
        $this->connection->commit();

        self::assertSame([1, 1, 2], $numbers);
    }

    public function test_handlers_create_and_fill_draft_without_route_planner(): void
    {
        [$vehicle, $driver] = $this->vehicle('30');
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->flush();
        $registry = new class($this->em, $this->connection) extends AbstractManagerRegistry {
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
        $vehicles = new VehicleRepository($registry);
        $repository = new WaybillRepository($this->em, $this->connection);
        $period = WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
        $locations = new LocationCatalog(new LocationRepository($registry), new LocationAccessControl(static::getContainer()->get(AccessGuard::class), $fetcher));
        $create = new CreateWaybillCommandHandler($vehicles, $repository, $access, new WaybillRouteBuilder(), new RefuelingOperationBuilder(), $locations);
        $id = $this->connection->transactional(fn (): string => $create(new CreateWaybillCommand($vehicle->getId(), $period, fuel: new Fuel((string) $vehicle->fuelRemaining(), 25, new Refuelings(), 'АИ-95'))));
        $draft = $repository->findOneById($id);
        self::assertInstanceOf(Waybill::class, $draft);
        self::assertSame(1, $draft->getNumber());
        self::assertSame(100, $draft->odometerStart());
        self::assertFalse($draft->hasRoute());

        $update = new UpdateWaybillCommandHandler($repository, $vehicles, $access, new WaybillRouteBuilder(), new RefuelingOperationBuilder(), $locations);
        $command = new UpdateWaybillCommand($id, 1, $period, null, new Fuel((string) $vehicle->fuelRemaining(), 25, new Refuelings(), 'АИ-95'), true, [], null,
            new TripInput(null, new \DateTimeImmutable('2026-01-10 10:00:00+00:00'), new Destination(new Location('Первая'), 2)),
            new TripInput(null, new \DateTimeImmutable('2026-01-10 12:00:00+00:00'), new Destination(new Location('Вторая'), 3)),
        );
        $this->connection->transactional(fn () => $update($command));
        $this->connection->executeStatement('UPDATE waybills_waybill SET fuel = NULL WHERE id = ?', [$id]);
        $this->em->clear();
        $lock = new LockWaybillCommandHandler($repository, $vehicles, $access);
        try {
            $this->connection->transactional(fn () => $lock(new LockWaybillCommand($id)));
            self::fail('Нельзя утвердить лист без данных о топливе.');
        } catch (AppException $exception) {
            self::assertStringContainsString('Заполните данные о топливе', $exception->getMessage());
        }
        self::assertFalse($draft->isLocked());
        self::assertFalse((bool) $this->connection->fetchOne('SELECT locked FROM waybills_waybill WHERE id = ?', [$id]));
        $this->em->clear();
        $loaded = (new WaybillRepository($this->em, $this->connection))->findOneById($id);
        self::assertInstanceOf(Waybill::class, $loaded);
        self::assertCount(2, $loaded->trips());
        self::assertSame(5, $loaded->route()->distance());
        self::assertSame(105, (int) $this->connection->fetchOne('SELECT odometer_end FROM waybills_waybill WHERE id = ?', [$id]));
        self::assertNull($this->connection->fetchOne('SELECT fuel FROM waybills_waybill WHERE id = ?', [$id]));
    }

    public function test_route_presence_is_not_confused_with_an_unset_route(): void
    {
        [$vehicle, $driver] = $this->vehicle();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $draft->setTrips();
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->persist($draft);
        $this->em->flush();
        $id = $draft->getId();
        $this->em->clear();

        $loaded = $this->em->find(Waybill::class, $id);
        self::assertInstanceOf(Waybill::class, $loaded);
        self::assertTrue($loaded->hasRoute());
        $loaded->restoreTrips();
        self::assertSame([], $loaded->trips());
    }

    public function test_saved_automatic_trips_can_be_restored_after_driver_leaves(): void
    {
        [$vehicle, $driver] = $this->vehicle();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $outward = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-10 10:00:00+00:00'), new Destination(new Location('Назначение'), 3), RouteLogOrigin::Automatic);
        $return = new RouteLog(Uuid::v7(), $vehicle, $outward, new \DateTimeImmutable('2026-01-10 12:00:00+00:00'), new Destination(new Location('База'), 3), RouteLogOrigin::Automatic);
        $draft->setTrips($outward, $return);
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->persist($outward);
        $this->em->persist($return);
        $this->em->persist($draft);
        $this->em->flush();
        $id = $draft->getId();
        $outwardId = $outward->id;
        $returnId = $return->id;
        $vehicle->leave($driver);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->find(Waybill::class, $id);
        $loadedOutward = $this->em->find(RouteLog::class, $outwardId);
        $loadedReturn = $this->em->find(RouteLog::class, $returnId);
        self::assertInstanceOf(Waybill::class, $loaded);
        self::assertInstanceOf(RouteLog::class, $loadedOutward);
        self::assertInstanceOf(RouteLog::class, $loadedReturn);
        self::assertNull($loaded->driver());
        $loaded->restoreTrips($loadedReturn, $loadedOutward);
        self::assertSame([$loadedOutward, $loadedReturn], $loaded->trips());

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('без водителя');
        $loaded->assertCanSave($loaded->vehicle());
    }

    public function test_approved_details_survive_reload_and_changes_to_vehicle_and_driver(): void
    {
        [$vehicle, $driver] = $this->vehicle('30');
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $trip = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-10 10:15:30.123456', new \DateTimeZone('Europe/Berlin')), new Destination(new Location('Назначение'), 3), RouteLogOrigin::Manual);
        $draft->revise($draft->period(), 103, new Fuel(30, 25, new Refuelings(new PositiveNumber(2.5)), 'АИ-95'), new Route($vehicle, $trip, $trip));
        $draft->lock();
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->persist($trip);
        $this->em->persist($draft);
        $this->em->flush();
        $id = $draft->getId();
        $tripId = $trip->id;

        $vehicle->rename('Новая модель');
        $vehicle->changeRegistrationNumber(new RegistrationNumber('B124CC77'));
        $vehicle->changeFuelTypes(FuelType::Ai92, FuelType::Ai92);
        $vehicle->setConsumptionRate(new PositiveNumber(12));
        $driver->changeDriverDetails($this->details('Новый водитель'));
        $driver->changeBase(new Location('Новая база'));
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->find(Waybill::class, $id);
        self::assertInstanceOf(Waybill::class, $loaded);
        $loadedTrip = $this->em->find(RouteLog::class, $tripId);
        self::assertInstanceOf(RouteLog::class, $loadedTrip);
        $loaded->restoreTrips($loadedTrip);
        self::assertTrue($loaded->isLocked());
        self::assertSame('Модель', $loaded->vehicleModel());
        self::assertSame('a123bc77', $loaded->vehicleRegistrationNumber()->value());
        self::assertSame([FuelType::Ai92], $loaded->vehicleFuelTypes());
        self::assertSame('АИ-95', $loaded->fuel()->type());
        self::assertSame(8, $loaded->vehicleConsumptionRate()?->value());
        self::assertSame('Водитель', $loaded->driver()?->fullName);
        self::assertSame('Europe/Berlin', $loaded->driver()->licenseIssuedAt->getTimezone()->getName());
        self::assertSame('База', $loaded->base()?->toString());
        self::assertSame('Новая модель', $loaded->vehicle()->getModel());
        self::assertSame(2.5, $loaded->fuel()->refuelings()->total());
        self::assertSame('Europe/Berlin', $loadedTrip->date->getTimezone()->getName());
        self::assertSame($trip->date->format('U.uP'), $loadedTrip->date->format('U.uP'));
        self::assertSame([$loadedTrip], $loaded->trips());
    }

    public function test_empty_draft_starts_with_the_initial_odometer(): void
    {
        [$vehicle, $driver] = $this->vehicle();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->persist($draft);
        $this->em->flush();
        $id = $draft->getId();
        $this->em->clear();

        $loaded = $this->em->find(Waybill::class, $id);
        self::assertInstanceOf(Waybill::class, $loaded);
        self::assertSame(100, $loaded->odometerEnd());
    }

    public function test_fuel_operations_preserve_vehicle_exact_amounts_dates_and_types(): void
    {
        [$vehicle, $driver] = $this->vehicle('30');
        $vehicle->changeFuelTypes(FuelType::Ai95, FuelType::Ai95, FuelType::Ai92);
        $date = new \DateTimeImmutable('2026-01-10 10:15:30.123456', new \DateTimeZone('Europe/Berlin'));
        $first = new FuelOperation(Uuid::v7(), $vehicle, $date, FuelType::Ai95, BigDecimal::of('0.1'), FuelOperationType::Refueling);
        $second = new FuelOperation(Uuid::v7(), $vehicle, $date, FuelType::Ai92, BigDecimal::of('0.20000000000000000001'), FuelOperationType::Refueling);
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();
        $firstId = $first->id;
        $secondId = $second->id;
        $vehicleId = $vehicle->getId();
        $this->em->clear();

        $loadedFirst = $this->em->find(FuelOperation::class, $firstId);
        $loadedSecond = $this->em->find(FuelOperation::class, $secondId);
        self::assertInstanceOf(FuelOperation::class, $loadedFirst);
        self::assertInstanceOf(FuelOperation::class, $loadedSecond);
        self::assertSame($vehicleId, $loadedFirst->vehicle->getId());
        self::assertSame($vehicleId, $loadedSecond->vehicle->getId());
        self::assertSame('0.1', (string) $loadedFirst->change);
        self::assertSame('0.20000000000000000001', (string) $loadedSecond->change);
        self::assertSame(FuelType::Ai95, $loadedFirst->fuelType);
        self::assertSame(FuelType::Ai92, $loadedSecond->fuelType);
        self::assertSame(FuelOperationType::Refueling, $loadedFirst->type);
        self::assertSame('Europe/Berlin', $loadedFirst->date->getTimezone()->getName());
        self::assertSame($date->format('U.uP'), $loadedFirst->date->format('U.uP'));
        self::assertNull($loadedFirst->waybill);
        self::assertSame('30', (string) $loadedFirst->vehicle->fuelRemaining());
        self::assertTrue((new \ReflectionProperty(FuelOperation::class, 'change'))->isReadOnly());
        self::assertTrue((new \ReflectionProperty(FuelOperation::class, 'id'))->isPrivateSet());
        self::assertTrue($loadedFirst->id->equals($firstId));
    }

    public function test_fuel_reconciliation_preserves_signed_amount_and_waybill_reference(): void
    {
        [$vehicle, $driver] = $this->vehicle('30');
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $reconciliation = new FuelOperation(Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-10T12:00:00+03:00'), FuelType::Ai95, BigDecimal::of('-15.125'), FuelOperationType::Reconciliation, $draft);
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->persist($draft);
        $this->em->persist($reconciliation);
        $this->em->flush();
        $id = $reconciliation->id;
        $waybillId = $draft->getId();
        $this->em->clear();

        $loaded = $this->em->find(FuelOperation::class, $id);
        self::assertInstanceOf(FuelOperation::class, $loaded);
        self::assertSame('-15.125', (string) $loaded->change);
        self::assertSame(FuelOperationType::Reconciliation, $loaded->type);
        self::assertSame($waybillId, $loaded->waybill?->getId());
    }

    public function test_repository_saves_and_edits_individual_refuelings_without_a_duplicate_total(): void
    {
        [$vehicle, $driver] = $this->vehicle('30');
        $vehicle->changeFuelTypes(FuelType::Ai95, FuelType::Ai95, FuelType::Ai92);
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->flush();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $trip = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Клиент'), 5), RouteLogOrigin::Manual);
        $date = new \DateTimeImmutable('2026-01-10 10:15:30.123456', new \DateTimeZone('Europe/Berlin'));
        $first = new FuelOperation(Uuid::v7(), $vehicle, $date, FuelType::Ai95, BigDecimal::of('0.1'), FuelOperationType::Refueling);
        $second = new FuelOperation(Uuid::v7(), $vehicle, $date, FuelType::Ai92, BigDecimal::of('0.20000000000000000001'), FuelOperationType::Refueling);
        $draft->reviseDraftWithRefuelingOperations($draft->period(), null, new Fuel((string) $draft->fuelStart(), 25, new Refuelings(), 'АИ-95'), new Route($vehicle, $trip, $trip), new RefuelingOperations($first, $second));
        $repository = new WaybillRepository($this->em, $this->connection);
        $repository->add($draft);
        $id = $draft->getId();
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log WHERE draft_waybill_id = ?', [$id]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_fuel_operation WHERE draft_waybill_id = ?', [$id]));
        self::assertNotNull($this->connection->fetchOne('SELECT fuel FROM waybills_waybill WHERE id = ?', [$id]));
        $this->em->clear();

        $repository = new WaybillRepository($this->em, $this->connection);
        $loaded = $repository->findOneById($id);
        self::assertInstanceOf(Waybill::class, $loaded);
        $refuelings = $loaded->refuelingOperations()->all();
        self::assertSame([(string) $first->id, (string) $second->id], array_map(static fn (FuelOperation $operation): string => (string) $operation->id, $refuelings));
        self::assertSame('0.30000000000000000001', (string) $loaded->refuelingOperations()->total());
        self::assertSame('Europe/Berlin', $refuelings[0]->date->getTimezone()->getName());
        self::assertSame($date->format('U.uP'), $refuelings[0]->date->format('U.uP'));

        $updated = new FuelOperation($refuelings[1]->id, $loaded->vehicle(), $date, FuelType::Ai92, BigDecimal::of('0.3'), FuelOperationType::Refueling);
        $added = new FuelOperation(Uuid::v7(), $loaded->vehicle(), $date, FuelType::Ai95, BigDecimal::of('0.4'), FuelOperationType::Refueling);
        $loaded->reviseDraftWithRefuelingOperations($loaded->period(), null, $loaded->fuel(), $loaded->route(), new RefuelingOperations($updated, $added));
        $repository->add($loaded);
        self::assertFalse($this->em->contains($refuelings[1]));
        $this->em->clear();
        $reloaded = (new WaybillRepository($this->em, $this->connection))->findOneById($id);
        self::assertInstanceOf(Waybill::class, $reloaded);
        self::assertSame([(string) $second->id, (string) $added->id], array_map(static fn (FuelOperation $operation): string => (string) $operation->id, $reloaded->refuelingOperations()->all()));
        self::assertSame('0.7', (string) $reloaded->refuelingOperations()->total());
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_fuel_operation WHERE id = ?', [(string) $first->id]));
    }

    public function test_failed_refueling_insert_rolls_back_sheet_and_trips(): void
    {
        [$vehicle, $driver] = $this->vehicle('30');
        $other = new Vehicle(Uuid::v7(), 'Другой', new RegistrationNumber('B124CC77'), new \DateTimeImmutable('2025-01-01'), new Location('Другая база'), 0, BigDecimal::zero());
        $existing = new FuelOperation(Uuid::v7(), $other, new \DateTimeImmutable('2026-01-10'), FuelType::Ai95, BigDecimal::of('5'), FuelOperationType::Refueling);
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->persist($other);
        $this->em->persist($existing);
        $this->em->flush();
        $draft = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $repository = new WaybillRepository($this->em, $this->connection);
        $repository->add($draft);
        $trip = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Клиент'), 5), RouteLogOrigin::Manual);
        $duplicate = new FuelOperation($existing->id, $vehicle, new \DateTimeImmutable('2026-01-10'), FuelType::Ai95, BigDecimal::of('2'), FuelOperationType::Refueling);
        $draft->reviseDraftWithRefuelingOperations($draft->period(), null, new Fuel((string) $draft->fuelStart(), 25, new Refuelings(), 'АИ-95'), new Route($vehicle, $trip, $trip), new RefuelingOperations($duplicate));

        try {
            $repository->add($draft);
            self::fail('Повторный идентификатор заправки должен отклонить всё сохранение.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
            self::assertSame(100, (int) $this->connection->fetchOne('SELECT odometer_end FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log WHERE draft_waybill_id = ?', [$draft->getId()]));
            self::assertSame($other->getId(), $this->connection->fetchOne('SELECT vehicle_id FROM waybills_fuel_operation WHERE id = ?', [(string) $existing->id]));
        }
    }

    /** @return array{Vehicle, DriverProfile} */
    private function vehicle(string $fuel = '30.00000000000000000001'): array
    {
        $vehicle = new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2025-01-01'), new Location('База'), 100, BigDecimal::of($fuel), new PositiveNumber(8));
        $driver = new DriverProfile(Uuid::v7(), 'owner', $this->details('Водитель'), new Location('База'));
        $vehicle->enter($driver);

        return [$vehicle, $driver];
    }

    private function details(string $name): DriverDetails
    {
        return new DriverDetails($name, '1234567890', new \DateTimeImmutable('2020-07-01 10:00:00.123456', new \DateTimeZone('Europe/Berlin')), '11223344595');
    }

    /** @param class-string<AbstractMigration> $migrationClass */
    private function applyTemporaryMigration(string $migrationClass): void
    {
        $migration = new $migrationClass($this->connection, new NullLogger());
        $migration->up(new Schema());
        $this->connection->beginTransaction();
        try {
            foreach ($migration->getSql() as $query) {
                $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $query->getStatement());
                $this->connection->executeStatement($sql, $query->getParameters(), $query->getTypes());
            }
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }
    }
}
