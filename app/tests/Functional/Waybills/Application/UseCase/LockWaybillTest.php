<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Application\UseCase;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Aggregate\ValueObject\Email;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\Audit\TrackedClassRepositoryInterface;
use App\Shared\Domain\Security\AuthUserInterface;
use App\Shared\Infrastructure\Audit\CachedAuditPolicy;
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
use App\Tests\Support\AuthenticatesActorTrait;
use App\Waybills\Application\UseCase\Command\LockWaybill\LockWaybillCommand;
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
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;
use App\Waybills\Domain\Repository\WaybillRepositoryInterface;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

final class LockWaybillTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private CommandBusInterface $bus;
    private VehicleRepositoryInterface $vehicles;
    private WaybillRepositoryInterface $waybills;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $container->set(CachedAuditPolicy::class, new CachedAuditPolicy($container->get(TrackedClassRepositoryInterface::class), new ArrayAdapter()));
        $this->em = $container->get(EntityManagerInterface::class);
        $connection = $this->em->getConnection();
        self::assertSame('test_db', $connection->getParams()['host'] ?? null);
        self::assertSame('app_test', $connection->getParams()['dbname'] ?? null);
        $connection->executeStatement('CREATE TEMPORARY TABLE audit_tracked_class (id UUID PRIMARY KEY, entity_class TEXT NOT NULL, fields JSON NOT NULL)');
        foreach ([Version20261121000000::class, Version20261121000001::class, Version20261121000002::class, Version20261121000003::class, Version20261121000004::class, Version20261121000005::class, Version20261121000006::class, Version20261121000007::class, Version20261121000008::class, Version20261121000009::class, Version20261121000010::class, Version20261122000000::class, Version20261122000001::class] as $migrationClass) {
            $this->applyTemporaryMigration($migrationClass);
        }
        $this->bus = $container->get(CommandBusInterface::class);
        $this->vehicles = $container->get(VehicleRepositoryInterface::class);
        $this->waybills = $container->get(WaybillRepositoryInterface::class);
        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->getConnection()->close();
        }
        parent::tearDown();
    }

    public function test_lock_moves_journal_and_updates_vehicle_state(): void
    {
        [$sheet, $vehicle] = $this->createSheet();
        self::assertTrue($this->bus->execute(new LockWaybillCommand($sheet->getId())));
        $this->em->clear();

        $stored = $this->waybills->findOneById($sheet->getId());
        $storedVehicle = $this->vehicles->findOneById($vehicle->getId());
        self::assertNotNull($stored);
        self::assertNotNull($storedVehicle);
        self::assertTrue($stored->isLocked());
        self::assertSame(100030, $stored->odometerEnd());
        self::assertSame(7, $stored->fuel()->remaining());
        self::assertSame(100030, $storedVehicle->currentOdometer());
        self::assertSame('7', (string) $storedVehicle->fuelRemaining());
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM waybills_route_log WHERE draft_waybill_id IS NULL'));
        $reconciliation = $this->em->getConnection()->fetchAssociative("SELECT amount::text AS amount, waybill_id, draft_waybill_id FROM waybills_fuel_operation WHERE type = 'reconciliation'");
        self::assertIsArray($reconciliation);
        self::assertSame('-3', $reconciliation['amount']);
        self::assertSame($sheet->getId(), $reconciliation['waybill_id']);
        self::assertNull($reconciliation['draft_waybill_id']);
    }

    public function test_lock_rejects_changed_vehicle_odometer_without_approving(): void
    {
        [$sheet, $vehicle] = $this->createSheet();
        $this->em->getConnection()->executeStatement('UPDATE waybills_vehicle SET current_odometer = 101000 WHERE id = ?', [$vehicle->getId()]);
        $this->em->clear();

        try {
            $this->bus->execute(new LockWaybillCommand($sheet->getId()));
            self::fail('Изменённое показание автомобиля должно отклонять утверждение.');
        } catch (AppException $exception) {
            self::assertStringContainsString('Показания автомобиля изменились', $exception->getMessage());
        }
        $this->em->clear();
        self::assertFalse($this->waybills->findOneById($sheet->getId())?->isLocked());
        self::assertSame(101000, $this->vehicles->findOneById($vehicle->getId())?->currentOdometer());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM waybills_fuel_operation WHERE type = 'reconciliation'"));
    }

    public function test_lock_rejects_changed_vehicle_fuel_without_approving(): void
    {
        [$sheet, $vehicle] = $this->createSheet();
        $this->em->getConnection()->executeStatement('UPDATE waybills_vehicle SET fuel_remaining = 20 WHERE id = ?', [$vehicle->getId()]);
        $this->em->clear();

        try {
            $this->bus->execute(new LockWaybillCommand($sheet->getId()));
            self::fail('Изменённый остаток автомобиля должен отклонять утверждение.');
        } catch (AppException $exception) {
            self::assertStringContainsString('Показания автомобиля изменились', $exception->getMessage());
        }
        $this->em->clear();
        self::assertFalse($this->waybills->findOneById($sheet->getId())?->isLocked());
        self::assertSame('20', (string) $this->vehicles->findOneById($vehicle->getId())?->fuelRemaining());
        self::assertSame(100000, $this->vehicles->findOneById($vehicle->getId())?->currentOdometer());
    }

    public function test_lock_uses_individual_refuelings_for_exact_reconciliation(): void
    {
        [$sheet, $vehicle] = $this->createSheet();
        $first = new FuelOperation(Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-10-01'), FuelType::Ai95, BigDecimal::of('0.1'), FuelOperationType::Refueling);
        $second = new FuelOperation(Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-10-01'), FuelType::Ai95, BigDecimal::of('0.20000000000000000001'), FuelOperationType::Refueling);
        $sheet->reviseDraftWithRefuelingOperations($sheet->period(), 100030, new Fuel(10, 7, null, 'АИ-95'), $sheet->route(), new RefuelingOperations($first, $second));
        $this->waybills->add($sheet);

        $this->bus->execute(new LockWaybillCommand($sheet->getId()));

        self::assertSame('-3.30000000000000000001', $this->em->getConnection()->fetchOne("SELECT amount::text FROM waybills_fuel_operation WHERE type = 'reconciliation'"));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM waybills_fuel_operation WHERE type = 'refueling' AND draft_waybill_id IS NULL"));
        self::assertSame('7', (string) $this->vehicles->findOneById($vehicle->getId())?->fuelRemaining());
    }

    public function test_lock_preserves_legacy_refueling_total(): void
    {
        [$sheet] = $this->createSheet();
        $sheet->reviseDraft($sheet->period(), 100030, new Fuel(10, 7, new Refuelings(new PositiveNumber(12.5)), 'АИ-95'), $sheet->route());
        $this->waybills->add($sheet);

        $this->bus->execute(new LockWaybillCommand($sheet->getId()));

        self::assertSame('-15.5', $this->em->getConnection()->fetchOne("SELECT amount::text FROM waybills_fuel_operation WHERE type = 'reconciliation'"));
        self::assertSame(12.5, $this->waybills->findOneById($sheet->getId())?->fuel()->refuelings()->total());
    }

    public function test_locked_sheet_cannot_be_saved_without_updating_vehicle(): void
    {
        [$sheet, $vehicle] = $this->createSheet();
        $sheet->lock();
        try {
            $this->waybills->add($sheet);
            self::fail('Фиксация должна проходить через атомарную операцию.');
        } catch (AppException) {
            $this->em->clear();
            self::assertFalse($this->waybills->findOneById($sheet->getId())?->isLocked());
            self::assertSame(100000, $this->vehicles->findOneById($vehicle->getId())?->currentOdometer());
        }
    }

    public function test_another_user_cannot_lock_the_sheet(): void
    {
        [$sheet, $vehicle] = $this->createSheet();
        $this->authenticateAs('another-user');

        try {
            $this->bus->execute(new LockWaybillCommand($sheet->getId()));
            self::fail('Чужой путевой лист нельзя зафиксировать.');
        } catch (ForbiddenException) {
            $this->em->clear();
            self::assertFalse($this->waybills->findOneById($sheet->getId())?->isLocked());
            self::assertSame(100000, $this->vehicles->findOneById($vehicle->getId())?->currentOdometer());
        }
    }

    public function test_locked_sheet_cannot_be_locked_again(): void
    {
        [$sheet] = $this->createSheet();
        $this->bus->execute(new LockWaybillCommand($sheet->getId()));
        $this->expectException(AppException::class);
        $this->bus->execute(new LockWaybillCommand($sheet->getId()));
    }

    public function test_sheet_lock_and_vehicle_update_roll_back_together(): void
    {
        [$sheet, $vehicle] = $this->createSheet();
        $connection = $this->em->getConnection();
        $connection->executeStatement("CREATE FUNCTION pg_temp.fail_waybill_reconciliation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Тестовый сбой сверки'; END $$");
        $connection->executeStatement("CREATE TRIGGER fail_waybill_reconciliation BEFORE INSERT ON waybills_fuel_operation FOR EACH ROW WHEN (NEW.type = 'reconciliation') EXECUTE FUNCTION pg_temp.fail_waybill_reconciliation()");

        try {
            $this->bus->execute(new LockWaybillCommand($sheet->getId()));
            self::fail('Ожидался сбой записи сверки.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('Тестовый сбой сверки', $exception->getMessage());
        }
        $this->em->clear();
        self::assertFalse($this->waybills->findOneById($sheet->getId())?->isLocked());
        self::assertSame(100000, $this->vehicles->findOneById($vehicle->getId())?->currentOdometer());
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log WHERE draft_waybill_id = ?', [$sheet->getId()]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM waybills_fuel_operation'));
    }

    /** @return array{Waybill, Vehicle} */
    private function createSheet(): array
    {
        $base = new Location('База');
        $destination = new Location('Клиент');
        $vehicle = new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2025-01-01'), $base, 100000, BigDecimal::of(10));
        $driver = new DriverProfile(Uuid::v7(), 'owner', new DriverDetails('Тестовый водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), $base);
        $vehicle->enter($driver);
        $this->em->persist($driver);
        $this->vehicles->add($vehicle);
        $day = new \DateTimeImmutable('2026-10-01');
        $first = new RouteLog(Uuid::v7(), $vehicle, null, $day, new Destination($destination, 15), RouteLogOrigin::Manual);
        $last = new RouteLog(Uuid::v7(), $vehicle, $first, $day, new Destination($base, 15), RouteLogOrigin::Manual);
        $route = new Route($vehicle, $first, $last);
        $sheet = Waybill::create(
            Uuid::v7(), 1, $vehicle,
            WaybillPeriod::fromDates(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')),
            100030, new Fuel(10, 7, new Refuelings(), 'АИ-95'), $route,
        );
        $this->waybills->add($sheet);

        return [$sheet, $vehicle];
    }

    /** @param class-string<AbstractMigration> $migrationClass */
    private function applyTemporaryMigration(string $migrationClass): void
    {
        $connection = $this->em->getConnection();
        $migration = new $migrationClass($connection, new NullLogger());
        $migration->up(new Schema());
        $connection->beginTransaction();
        try {
            foreach ($migration->getSql() as $query) {
                $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $query->getStatement());
                $connection->executeStatement($sql, $query->getParameters(), $query->getTypes());
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    private function authenticateAs(string $id): void
    {
        $user = new class($id) implements AuthUserInterface {
            public function __construct(private string $id)
            {
            }

            public function getUlid(): string
            {
                return $this->id;
            }

            public function getEmail(): Email
            {
                return new Email('test@example.com');
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function getPassword(): ?string
            {
                return null;
            }

            public function getUserIdentifier(): string
            {
                return $this->id;
            }

            public function eraseCredentials(): void
            {
            }
        };
        static::getContainer()->get(TokenStorageInterface::class)->setToken(new PreAuthenticatedToken($user, 'test', $user->getRoles()));
    }
}
