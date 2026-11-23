<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Infrastructure\Repository;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
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
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refueling;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use App\Waybills\Infrastructure\Repository\WaybillRepository;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManager $em;
    private WaybillRepository $repository;
    private string $schema;
    private bool $ownsSchema = false;
    private int $registrationSequence = 0;
    /** @var array<string, mixed> */
    private array $params;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->params = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->getParams();
        self::assertSame('test_db', $this->params['host'] ?? null);
        self::assertSame('app_test', $this->params['dbname'] ?? null);
        $this->connection = DriverManager::getConnection($this->params);
        self::assertSame('app_test', $this->connection->fetchOne('SELECT current_database()'));
        $this->schema = 'wbtest_'.str_replace('-', '', (string) Uuid::v4());
        $this->connection->executeStatement('CREATE SCHEMA '.$this->schema);
        $this->ownsSchema = true;
        $this->connection->executeStatement('SET search_path TO '.$this->schema);
        foreach ([Version20261121000000::class, Version20261121000001::class, Version20261121000002::class, Version20261121000003::class, Version20261121000004::class, Version20261121000005::class, Version20261121000006::class, Version20261121000007::class, Version20261121000008::class, Version20261121000009::class, Version20261121000010::class, Version20261122000000::class, Version20261122000001::class] as $class) {
            // Run each migration twice to verify its idempotence.
            for ($i = 0; $i < 2; ++$i) {
                $migration = new $class($this->connection, new NullLogger());
                $migration->up(new Schema());
                $this->connection->transactional(function () use ($migration): void {
                    foreach ($migration->getSql() as $query) {
                        $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                    }
                });
            }
        }
        $config = static::getContainer()->get(EntityManagerInterface::class)->getConfiguration();
        $this->em = new EntityManager($this->connection, $config);
        $this->repository = new WaybillRepository($this->em, $this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->close();
        }
        if (isset($this->connection)) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if ($this->ownsSchema) {
                $this->connection->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');
            }
            $this->connection->close();
        }
        parent::tearDown();
    }

    public function test_round_trip_snapshots_lock_and_domain_validation(): void
    {
        $vehicle = $this->vehicle('AA123');
        $sheet = $this->sheet($vehicle, '2026-10-01', '2026-10-05', true);
        $this->repository->add($sheet);
        $this->repository->finalize($sheet);
        $loaded = $this->repository->findOneById($sheet->getId());
        self::assertNotNull($loaded);
        self::assertEquals($sheet, $loaded);
        self::assertTrue($loaded->isLocked());
        self::assertSame('Иванов Иван', $loaded->driver()->fullName);
        self::assertSame($sheet->vehicleFuelTypes(), $loaded->vehicleFuelTypes());
        self::assertSame(12.5, $loaded->fuel()->refuelings()->total());
        self::assertCount(2, iterator_to_array($loaded->route()->logs(), false));
        self::assertCount(1, $loaded->absences());
        self::assertNull($this->repository->findOneById((string) Uuid::v7()));
        $this->expectException(AppException::class);
        $this->repository->add($loaded);
    }

    public function test_periods_are_global_to_vehicle_and_include_both_boundary_days(): void
    {
        $first = $this->vehicle('BB123');
        $second = $this->vehicle('CC123');
        $firstSheet = $this->sheet($first, '2026-10-01', '2026-10-03');
        $this->repository->add($firstSheet);
        $this->repository->finalize($firstSheet);
        $secondSheet = $this->sheet($first, '2026-10-04', '2026-10-06');
        $this->repository->add($secondSheet);
        $this->repository->finalize($secondSheet);
        $this->repository->add($this->sheet($first, '2026-10-07', '2026-10-09'));
        $this->repository->add($this->sheet($second, '2026-10-01', '2026-10-09'));
        try {
            $this->repository->add($this->sheet($first, '2026-10-09', '2026-10-10', false, 'another owner'));
            self::fail('The shared day must overlap.');
        } catch (AppException $e) {
            self::assertStringNotContainsString('owner', $e->getMessage());
            self::assertStringNotContainsString('Иванов', $e->getMessage());
        }
    }

    public function test_revision_is_checked_against_current_approved_periods(): void
    {
        $vehicle = $this->vehicle('DD123');
        $previous = $this->sheet($vehicle, '2026-10-01', '2026-10-02', true);
        $previous->lock();
        foreach ($previous->trips() as $trip) {
            $this->em->persist($trip);
        }
        $this->em->persist($previous);
        $this->em->flush();
        $sheet = $this->sheet($vehicle, '2026-10-03', '2026-10-04');
        $this->repository->add($sheet);
        $sheet->reviseDraft($this->period('2026-10-02', '2026-10-03'), 100, $sheet->fuel(), new Route($vehicle));
        $this->expectException(AppException::class);
        $this->repository->add($sheet);
    }

    public function test_another_owner_must_wait_until_the_draft_is_approved(): void
    {
        $vehicle = $this->vehicle('NN123');
        $draft = $this->sheet($vehicle, '2026-10-01', '2026-10-31', owner: 'first');
        $this->repository->add($draft);
        $firstDriver = $vehicle->driver();
        self::assertNotNull($firstDriver);
        $secondDriver = new DriverProfile(Uuid::v7(), 'second', new DriverDetails('Другой водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), new Location('База'));
        $vehicle->leave($firstDriver);
        $vehicle->enter($secondDriver);
        $this->em->persist($secondDriver);
        $this->em->flush();
        $next = $this->sheet($vehicle, '2026-11-01', '2026-11-30', owner: 'second');

        try {
            $this->repository->add($next);
            self::fail('Второй черновик автомобиля должен быть отклонён.');
        } catch (AppException $e) {
            self::assertStringContainsString('черновик', $e->getMessage());
            self::assertStringNotContainsString('first', $e->getMessage());
        }

        $this->repository->finalize($draft);
        $this->repository->add($next);
        self::assertNotNull($this->repository->findOneById($next->getId()));
    }

    public function test_database_rejects_second_draft_even_when_application_checks_are_bypassed(): void
    {
        $vehicle = $this->vehicle('PP123');
        $sheet = $this->sheet($vehicle, '2026-10-01', '2026-10-31');
        $this->repository->add($sheet);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->executeStatement(
            'INSERT INTO waybills_waybill (id, vehicle_id, number, period_from, period_to, locked, odometer_start, fuel_start) SELECT ?, vehicle_id, number, period_from, period_to, locked, odometer_start, fuel_start FROM waybills_waybill WHERE id = ?',
            [(string) Uuid::v7(), $sheet->getId()],
        );
    }

    public function test_old_nonoverlapping_period_cannot_be_created_after_approval(): void
    {
        $vehicle = $this->vehicle('QQ123');
        $approved = $this->sheet($vehicle, '2026-10-01', '2026-10-31');
        $this->repository->add($approved);
        $this->repository->finalize($approved);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('после последнего утверждённого');
        $this->repository->add($this->sheet($vehicle, '2026-09-01', '2026-09-30'));
    }

    public function test_vehicle_is_immutable_and_stale_copy_cannot_overwrite(): void
    {
        $vehicle = $this->vehicle('HH123');
        $sheet = $this->sheet($vehicle, '2026-10-01', '2026-10-02');
        $this->repository->add($sheet);
        self::assertTrue((new \ReflectionProperty(Waybill::class, 'vehicle'))->isReadOnly());
        $otherManager = new EntityManager($this->connection, $this->em->getConfiguration());
        $otherRepository = new WaybillRepository($otherManager, $this->connection);
        $stale = $otherRepository->findOneById($sheet->getId());
        self::assertInstanceOf(Waybill::class, $stale);
        $this->addTrip($sheet, 1);
        $this->repository->add($sheet);
        $this->addTrip($stale, 2);
        $this->expectException(AppException::class);
        $otherRepository->add($stale);
    }

    public function test_snapshot_isolation_is_rejected(): void
    {
        $sheet = $this->sheet($this->vehicle('JJ123'), '2026-10-01', '2026-10-02');
        $this->connection->beginTransaction();
        $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        try {
            $this->repository->add($sheet);
            self::fail('Snapshot isolation cannot guarantee fresh periods after the lock.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('READ COMMITTED', $e->getMessage());
        } finally {
            $this->connection->rollBack();
        }
    }

    public function test_stale_edit_cannot_overwrite_a_newer_edit(): void
    {
        $sheet = $this->sheet($this->vehicle('MM123'), '2026-10-01', '2026-10-02');
        $this->repository->add($sheet);
        $otherManager = new EntityManager($this->connection, $this->em->getConfiguration());
        $otherRepository = new WaybillRepository($otherManager, $this->connection);
        $stale = $otherRepository->findOneById($sheet->getId());
        self::assertInstanceOf(Waybill::class, $stale);
        $this->addTrip($sheet, 1);
        $this->repository->add($sheet);
        $this->addTrip($stale, 2);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('изменился');
        $otherRepository->add($stale);
    }

    public function test_creates_and_edits_wait_for_vehicle_locks(): void
    {
        $a = $this->vehicle('FF123');
        $b = $this->vehicle('GG123');
        $sheet = $this->sheet($a, '2026-10-01', '2026-10-02');
        $this->repository->add($sheet);
        $other = DriverManager::getConnection($this->params);
        $other->executeStatement('SET search_path TO '.$this->schema);
        $other->executeStatement("SET lock_timeout = '100ms'");
        $otherManager = new EntityManager($other, $this->em->getConfiguration());
        $repo = new WaybillRepository($otherManager, $other);
        $loaded = $repo->findOneById($sheet->getId());
        self::assertInstanceOf(Waybill::class, $loaded);
        $this->addTrip($loaded, 1);
        $otherVehicle = $otherManager->find(Vehicle::class, $b->getId());
        self::assertInstanceOf(Vehicle::class, $otherVehicle);
        $new = Waybill::createDraft(Uuid::v7(), 1, $otherVehicle, new DateTimeInterval(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-02')));
        foreach ([[$a, $loaded], [$b, $new]] as [$lockedVehicle, $candidate]) {
            $this->connection->beginTransaction();
            $this->connection->fetchOne('SELECT id FROM waybills_vehicle WHERE id = ? FOR UPDATE', [$lockedVehicle->getId()]);
            try {
                $repo->add($candidate);
                self::fail('Сохранение должно ждать блокировки автомобиля.');
            } catch (DriverException $e) {
                self::assertSame('55P03', $e->getSQLState());
            } finally {
                $this->connection->rollBack();
            }
        }
        $otherManager->close();
        $other->close();
    }

    public function test_remove_deletes_only_draft_and_its_journal_rows(): void
    {
        $vehicle = $this->vehicle('RR123');
        $sheet = $this->sheet($vehicle, '2026-10-01', '2026-10-02');
        $this->addTrip($sheet, 1);
        $refueling = new FuelOperation(Uuid::v7(), $vehicle, $sheet->period()->from(), FuelType::Ai95, BigDecimal::of('0.123456789012345678901'), FuelOperationType::Refueling);
        $sheet->reviseDraftWithRefuelingOperations($sheet->period(), $sheet->odometerEnd(), $sheet->fuel(), $sheet->route(), new RefuelingOperations($refueling));
        $this->repository->add($sheet);

        $this->repository->remove($sheet);

        self::assertNull($this->repository->findOneById($sheet->getId()));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_fuel_operation'));
        self::assertNotNull($this->em->find(Vehicle::class, $vehicle->getId()));
        $this->repository->add($this->sheet($vehicle, '2026-10-01', '2026-10-02'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
    }

    public function test_stale_copy_cannot_delete_a_newer_draft(): void
    {
        $sheet = $this->sheet($this->vehicle('SS123'), '2026-10-01', '2026-10-02');
        $this->repository->add($sheet);
        $otherManager = new EntityManager($this->connection, $this->em->getConfiguration());
        $otherRepository = new WaybillRepository($otherManager, $this->connection);
        $stale = $otherRepository->findOneById($sheet->getId());
        self::assertInstanceOf(Waybill::class, $stale);
        $this->addTrip($sheet, 1);
        $this->repository->add($sheet);

        try {
            $otherRepository->remove($stale);
            self::fail('Устаревшая копия не должна удалять изменённый черновик.');
        } catch (AppException $exception) {
            self::assertStringContainsString('изменился', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
    }

    public function test_approved_sheet_and_its_history_cannot_be_removed(): void
    {
        $sheet = $this->sheet($this->vehicle('TT123'), '2026-10-01', '2026-10-02', true);
        $this->repository->add($sheet);
        $this->repository->finalize($sheet);

        try {
            $this->repository->remove($sheet);
            self::fail('Утверждённый лист нельзя удалить.');
        } catch (AppException $exception) {
            self::assertStringContainsString('нельзя изменять или удалять', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM waybills_fuel_operation WHERE type = 'reconciliation'"));
    }

    private function addTrip(Waybill $waybill, int $distance): void
    {
        $trip = new RouteLog(Uuid::v7(), $waybill->vehicle(), null, $waybill->period()->from(), new Destination(new Location('Назначение'), $distance), RouteLogOrigin::Manual);
        $waybill->setTrips($trip);
    }

    private function vehicle(string $number): Vehicle
    {
        $registration = sprintf('a%03dbc77', ++$this->registrationSequence);
        $base = new Location('База');
        $vehicle = new Vehicle(Uuid::v7(), 'КамАЗ', new RegistrationNumber($registration), new \DateTimeImmutable('2025-01-01'), $base, 100, BigDecimal::of(10), new PositiveNumber(32.5));
        $driver = new DriverProfile(Uuid::v7(), 'owner-'.$number, new DriverDetails('Иванов Иван', '1234567890', new \DateTimeImmutable('2020-01-01T12:34:56.123456+03:00'), '11223344595'), $base);
        $vehicle->enter($driver);
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->flush();

        return $vehicle;
    }

    private function sheet(Vehicle $vehicle, string $from, string $to, bool $detailed = false, string $owner = 'owner'): Waybill
    {
        $base = new Location('База');
        $destination = new Location('Клиент');
        $date = new \DateTimeImmutable($from.'T12:34:56.123456+03:00');
        $first = $detailed ? new RouteLog(Uuid::v7(), $vehicle, null, $date, new Destination($destination, 15), RouteLogOrigin::Automatic) : null;
        $last = null === $first ? null : new RouteLog(Uuid::v7(), $vehicle, $first, $date, new Destination($base, 15), RouteLogOrigin::Automatic);
        $route = new Route($vehicle, $first, $last);
        $fuel = $detailed ? new Fuel(10, 15, new Refuelings(null, new Refueling($date, new PositiveNumber(12.5))), 'АИ-95') : new Fuel(10, 10, new Refuelings(), 'АИ-95');
        $absences = $detailed ? [$this->period($to, $to)] : [];

        return Waybill::create(Uuid::v7(), 1, $vehicle, $this->period($from, $to), $detailed ? 130 : 100, $fuel, $route, ...$absences);
    }

    private function period(string $from, string $to): WaybillPeriod
    {
        return WaybillPeriod::fromDates(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }
}
