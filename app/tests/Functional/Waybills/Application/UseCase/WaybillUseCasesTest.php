<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Application\UseCase;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
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
use App\Waybills\Application\DTO\TripInput;
use App\Waybills\Application\UseCase\Command\CreateWaybill\CreateWaybillCommand;
use App\Waybills\Application\UseCase\Command\DeleteWaybill\DeleteWaybillCommand;
use App\Waybills\Application\UseCase\Command\FillWaybillRoute\FillWaybillRouteCommand;
use App\Waybills\Application\UseCase\Command\UpdateWaybill\UpdateWaybillCommand;
use App\Waybills\Application\UseCase\Query\GetPagedWaybills\GetPagedWaybillsQuery;
use App\Waybills\Application\UseCase\Query\GetWaybill\GetWaybillQuery;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refueling;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use App\Waybills\Domain\Repository\DriverProfileRepositoryInterface;
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;
use App\Waybills\Domain\Repository\WaybillFilter;
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

final class WaybillUseCasesTest extends KernelTestCase
{
    private CommandBusInterface $commands;
    private QueryBusInterface $queries;
    private EntityManagerInterface $em;
    private VehicleRepositoryInterface $vehicles;
    private DriverProfileRepositoryInterface $profiles;
    private WaybillRepositoryInterface $waybills;
    private Vehicle $vehicle;
    private string $owner;
    private string $other;

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
        $this->commands = $container->get(CommandBusInterface::class);
        $this->queries = $container->get(QueryBusInterface::class);
        $this->vehicles = $container->get(VehicleRepositoryInterface::class);
        $this->profiles = $container->get(DriverProfileRepositoryInterface::class);
        $this->waybills = $container->get(WaybillRepositoryInterface::class);
        $this->owner = (string) Uuid::v7();
        $this->other = (string) Uuid::v7();
        $profile = $this->profile($this->owner, 'Клиент');
        $this->profile($this->other, 'Другой клиент');
        $this->vehicle = new Vehicle(Uuid::v7(), 'Автомобиль', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2025-01-01'), new Location('База'), 100, BigDecimal::of(10));
        $this->vehicle->enter($profile);
        $this->vehicles->add($this->vehicle);
        $this->authenticateAs($this->owner);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
        parent::tearDown();
    }

    public function test_shared_vehicle_scope_and_admin_owner_profile(): void
    {
        $firstPeriod = $this->period('2026-10-01', '2026-10-10');
        $first = $this->commands->execute(new CreateWaybillCommand($this->vehicle->getId(), $firstPeriod));
        $this->authenticateAs($this->other);
        try {
            $overlappingPeriod = $this->period('2026-10-10', '2026-10-20');
            $this->commands->execute(new CreateWaybillCommand($this->vehicle->getId(), $overlappingPeriod));
            self::fail('Посторонний водитель не может создать лист занятого автомобиля.');
        } catch (ForbiddenException) {
        }
        try {
            $this->queries->execute(new GetWaybillQuery($first));
            self::fail('Чужой лист недоступен.');
        } catch (ForbiddenException) {
        }
        try {
            $this->commands->execute(new UpdateWaybillCommand($first, 1, $firstPeriod, null, null, false));
            self::fail('Редактирование чужого листа запрещено.');
        } catch (ForbiddenException) {
        }
        try {
            $this->commands->execute(new DeleteWaybillCommand($first, 1));
            self::fail('Удаление чужого листа запрещено.');
        } catch (ForbiddenException) {
        }
        self::assertNotNull($this->waybills->findOneById($first));
        $this->authenticateAs('admin-'.Uuid::v7(), ['ROLE_ADMIN']);
        $this->commands->execute(new UpdateWaybillCommand($first, 1, $firstPeriod, null, null, false));
        $others = $this->queries->execute(new GetPagedWaybillsQuery(new WaybillFilter(driverOwnerId: $this->owner, vehicleId: $this->vehicle->getId())));
        self::assertSame(0, $others->total);
        $this->authenticateAs($this->owner, ['ROLE_ADMIN']);
        $own = $this->queries->execute(new GetPagedWaybillsQuery(new WaybillFilter(driverOwnerId: $this->other, vehicleId: $this->vehicle->getId())));
        self::assertSame(1, $own->total);
        self::assertSame($first, $own->items[0]->getId());
    }

    public function test_owner_can_delete_draft_without_reusing_its_number(): void
    {
        $period = $this->period('2026-10-01', '2026-10-10');
        $id = $this->commands->execute(new CreateWaybillCommand($this->vehicle->getId(), $period));

        $this->commands->execute(new DeleteWaybillCommand($id, 1));

        $this->em->clear();
        self::assertNull($this->waybills->findOneById($id));
        $next = $this->commands->execute(new CreateWaybillCommand($this->vehicle->getId(), $period));
        self::assertSame(2, $this->sheet($next)->getNumber());
    }

    public function test_unlisted_fuel_type_is_rejected(): void
    {
        $period = $this->period('2026-10-01', '2026-10-10');
        $id = $this->commands->execute(new CreateWaybillCommand($this->vehicle->getId(), $period));
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('марка топлива');
        $this->commands->execute(new UpdateWaybillCommand($id, 1, $period, null, new Fuel(10, 5, new Refuelings(), 'АИ-92'), false));
    }

    public function test_manual_routes_survive_creation_and_failed_update(): void
    {
        $period = $this->period('2026-10-01', '2026-10-10');
        $date = new \DateTimeImmutable('2026-10-03');
        $id = $this->commands->execute(new CreateWaybillCommand($this->vehicle->getId(), $period));
        $this->commands->execute(new UpdateWaybillCommand($id, 1, $period, 120, $this->fuel(), true, [], null,
            new TripInput(null, $date, new Destination(new Location('Ручная точка'), 10)),
            new TripInput(null, $date, new Destination(new Location('База'), 10)),
        ));
        $sheet = $this->sheet($id);
        $manual = $sheet->trips();
        self::assertCount(2, $manual);
        self::assertSame([RouteLogOrigin::Manual, RouteLogOrigin::Manual], array_map(static fn (RouteLog $log): RouteLogOrigin => $log->origin, $manual));
        $inputs = array_map(static fn (RouteLog $log): TripInput => new TripInput($log->id, $log->date, $log->destination), $manual);
        try {
            $this->commands->execute(new UpdateWaybillCommand($id, $this->waybills->versionOf($sheet), $period, 119, $this->fuel(), true, [], null, ...$inputs));
            self::fail('Пробег поездок больше разницы показаний.');
        } catch (AppException $exception) {
            self::assertStringContainsString('не может превышать', $exception->getMessage());
        }
        $unchanged = $this->sheet($id);
        self::assertSame(120, $unchanged->odometerEnd());
        self::assertSame(array_map(static fn (RouteLog $log): string => (string) $log->id, $manual), array_map(static fn (RouteLog $log): string => (string) $log->id, $unchanged->trips()));
        try {
            $this->commands->execute(new UpdateWaybillCommand($id, $this->waybills->versionOf($unchanged), $period, 121, $this->fuel(), true, [], null, ...$inputs));
            self::fail('Показание больше рассчитанного пробега.');
        } catch (AppException $exception) {
            self::assertStringContainsString('должен совпадать', $exception->getMessage());
        }
        $unchanged = $this->sheet($id);
        self::assertSame(120, $unchanged->odometerEnd());
        self::assertSame(array_map(static fn (RouteLog $log): string => (string) $log->id, $manual), array_map(static fn (RouteLog $log): string => (string) $log->id, $unchanged->trips()));
    }

    public function test_creates_draft_with_trips_and_partial_fuel_in_one_command(): void
    {
        $period = $this->period('2026-10-01', '2026-10-10');
        $fuel = new Fuel(10, null, new Refuelings(new PositiveNumber(12.5)), 'АИ-95');
        $id = $this->commands->execute(new CreateWaybillCommand($this->vehicle->getId(), $period, 315, $fuel, true, 100, '10', null, [],
            new TripInput(null, new \DateTimeImmutable('2026-10-03'), new Destination(new Location('Первая точка'), 15)),
            new TripInput(null, new \DateTimeImmutable('2026-10-04'), new Destination(new Location('Вторая точка'), 200)),
        ));

        $sheet = $this->sheet($id);
        self::assertSame(1, $sheet->getNumber());
        self::assertSame(315, $sheet->odometerEnd());
        self::assertSame(215, $sheet->route()->distance());
        self::assertCount(2, $sheet->trips());
        self::assertSame(12.5, $sheet->fuel()->refuelings()->total());
        self::assertNull($sheet->fuel()->remainingOrNull());
        self::assertSame(1, $this->waybills->versionOf($sheet));
    }

    public function test_failed_full_creation_preserves_number_and_writes_nothing(): void
    {
        $period = $this->period('2026-10-01', '2026-10-10');
        foreach ([114, 116] as $invalidOdometer) {
            try {
                $this->commands->execute(new CreateWaybillCommand($this->vehicle->getId(), $period, $invalidOdometer, null, true, 100, '10', null, [],
                    new TripInput(null, new \DateTimeImmutable('2026-10-03'), new Destination(new Location('Точка'), 15)),
                ));
                self::fail('Показание не совпадает с поездками.');
            } catch (AppException) {
                self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
                self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
                self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT next_waybill_number FROM waybills_driver_profile WHERE owner_id = ?', [$this->owner]));
            }
        }
        $id = $this->commands->execute(new CreateWaybillCommand($this->vehicle->getId(), $period));
        self::assertSame(1, $this->sheet($id)->getNumber());
    }

    public function test_full_creation_rejects_stale_initial_readings_without_consuming_number(): void
    {
        $period = $this->period('2026-10-01', '2026-10-10');
        foreach ([[99, '10'], [100, '10.00000000000000000001']] as [$odometer, $fuel]) {
            try {
                $this->commands->execute(new CreateWaybillCommand($this->vehicle->getId(), $period, null, null, false, $odometer, $fuel));
                self::fail('Устаревшие начальные показания должны быть отклонены.');
            } catch (AppException $exception) {
                self::assertStringContainsString('Показания автомобиля изменились', $exception->getMessage());
                self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
                self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT next_waybill_number FROM waybills_driver_profile WHERE owner_id = ?', [$this->owner]));
            }
        }
    }

    public function test_fill_route_returns_trips_without_saving_waybill(): void
    {
        $command = new CreateWaybillCommand(
            $this->vehicle->getId(),
            $this->period('2026-10-01', '2026-10-10'),
            odometerEnd: 160,
            routePresent: true,
            expectedOdometerStart: 100,
            expectedFuelStart: '10',
        );

        $result = $this->commands->execute(new FillWaybillRouteCommand($command));

        self::assertTrue($result->isSuccessful(), $result->error() ?? '');
        $route = $result->route();
        self::assertNotNull($route);
        self::assertSame(60, $route->distance());
        foreach ($route->logs() as $trip) {
            self::assertSame(RouteLogOrigin::Automatic, $trip->origin);
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
    }

    public function test_refueling_in_next_sheet_does_not_require_trip_in_current_or_previous_sheet(): void
    {
        $base = new Location('База');
        $target = new Location('Клиент');
        $date = new \DateTimeImmutable('2026-10-05');
        $period = $this->period('2026-10-01', '2026-10-05');
        $first = new RouteLog(Uuid::v7(), $this->vehicle, null, $date, new Destination($target, 10), RouteLogOrigin::Manual);
        $last = new RouteLog(Uuid::v7(), $this->vehicle, $first, $date, new Destination($base, 10), RouteLogOrigin::Manual);
        $approved = Waybill::create(Uuid::v7(), 1, $this->vehicle, $period, 120, $this->fuel(), new Route($this->vehicle, $first, $last));
        $approved->lock();
        $this->em->persist($first);
        $this->em->persist($last);
        $this->em->persist($approved);
        $this->em->flush();
        $fuel = new Fuel(10, 10, new Refuelings(null, new Refueling(new \DateTimeImmutable('2026-10-06'), new PositiveNumber(5))), 'АИ-95');
        $nextPeriod = $this->period('2026-10-06', '2026-10-10');
        $id = $this->commands->execute(new CreateWaybillCommand($this->vehicle->getId(), $nextPeriod));

        $this->commands->execute(new UpdateWaybillCommand($id, 1, $nextPeriod, null, $fuel, true));

        $sheet = $this->sheet($id);
        self::assertSame(5, $sheet->fuel()->refuelings()->total());
    }

    private function sheet(string $id): Waybill
    {
        $this->em->clear();
        $sheet = $this->queries->execute(new GetWaybillQuery($id));
        self::assertInstanceOf(Waybill::class, $sheet);

        return $sheet;
    }

    private function profile(string $owner, string $destination): DriverProfile
    {
        $profile = new DriverProfile(Uuid::v7(), $owner, new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), new Location('База'), new Destination(new Location($destination), 15));
        $this->profiles->add($profile);

        return $profile;
    }

    private function fuel(): Fuel
    {
        return new Fuel(10, 5, new Refuelings(), 'АИ-95');
    }

    private function period(string $from, string $to): WaybillPeriod
    {
        return WaybillPeriod::fromDates(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
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

    /** @param list<string> $roles */
    private function authenticateAs(string $id, array $roles = ['ROLE_USER']): void
    {
        $user = new class($id, $roles) implements AuthUserInterface {
            /** @param list<string> $roles */
            public function __construct(private string $id, private array $roles)
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
                return $this->roles;
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
