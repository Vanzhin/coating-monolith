<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Application\UseCase;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\ValueObject\Email;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\PaginationResult;
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
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\Service\AccessControl\WaybillAccessControl;
use App\Waybills\Application\UseCase\Command\EnterVehicle\EnterVehicleCommand;
use App\Waybills\Application\UseCase\Command\LeaveVehicle\LeaveVehicleCommand;
use App\Waybills\Application\UseCase\Query\GetPagedVehicles\GetPagedVehiclesQuery;
use App\Waybills\Application\UseCase\Query\GetPagedWaybills\GetPagedWaybillsQuery;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use App\Waybills\Domain\Repository\VehicleFilter;
use App\Waybills\Domain\Repository\WaybillFilter;
use App\Waybills\Domain\Repository\WaybillRepositoryInterface;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

final class WaybillDriverAccessTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandBusInterface $commands;
    private QueryBusInterface $queries;
    private string $vehicleId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->commands = static::getContainer()->get(CommandBusInterface::class);
        $this->queries = static::getContainer()->get(QueryBusInterface::class);
        $connection = $this->em->getConnection();
        self::assertSame('test_db', $connection->getParams()['host'] ?? null);
        self::assertSame('app_test', $connection->getParams()['dbname'] ?? null);

        foreach ([Version20261121000000::class, Version20261121000001::class, Version20261121000002::class, Version20261121000003::class, Version20261121000004::class, Version20261121000005::class, Version20261121000006::class, Version20261121000007::class, Version20261121000008::class, Version20261121000009::class] as $migrationClass) {
            $migration = new $migrationClass($connection, new NullLogger());
            $migration->up(new Schema());
            $connection->transactional(function () use ($migration, $connection): void {
                foreach ($migration->getSql() as $query) {
                    $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $query->getStatement());
                    $connection->executeStatement($sql, $query->getParameters(), $query->getTypes());
                }
            });
        }

        $vehicle = new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('a123bc77'), new \DateTimeImmutable('2026-01-01'), new Location('База'), 100, BigDecimal::of('30'));
        $first = $this->driver('first');
        $vehicle->enter($first);
        $this->vehicleId = $vehicle->getId();
        $approved = Waybill::create(Uuid::v7(), 1, $vehicle, WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')), 100, new Fuel(30, 30, new Refuelings(), 'АИ-95'), new Route($vehicle));
        $approved->lock();
        $draft = Waybill::createDraft(Uuid::v7(), 2, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-28')));
        $vehicle->leave($first);
        $this->em->persist($vehicle);
        $this->em->persist($first);
        $this->em->persist($this->driver('second'));
        $this->em->persist($approved);
        $this->em->persist($draft);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
        parent::tearDown();
    }

    public function test_access_and_list_counts_follow_the_current_driver(): void
    {
        $this->authenticateAs('first');
        $this->commands->execute(new EnterVehicleCommand($this->vehicleId));
        $this->em->clear();
        self::assertTrue($this->access()->canEdit($this->vehicle()));
        self::assertSame(2, $this->countVisibleWaybills());

        $this->authenticateAs('second');
        self::assertFalse($this->access()->canEdit($this->vehicle()));
        // A forged filter cannot reveal the first driver's documents.
        self::assertSame(0, $this->countVisibleWaybills('first'));

        $this->authenticateAs('first');
        $this->commands->execute(new LeaveVehicleCommand($this->vehicleId));
        $this->em->clear();
        self::assertFalse($this->access()->canEdit($this->vehicle()));
        self::assertSame(0, $this->countVisibleWaybills());

        $this->authenticateAs('second');
        $this->commands->execute(new EnterVehicleCommand($this->vehicleId));
        $this->em->clear();
        self::assertTrue($this->access()->canEdit($this->vehicle()));
        self::assertSame(2, $this->countVisibleWaybills());
        $this->authenticateAs('first');
        self::assertFalse($this->access()->canEdit($this->vehicle()));
        self::assertSame(0, $this->countVisibleWaybills());
    }

    public function test_manager_list_is_scoped_to_own_profile_and_anonymous_user_is_rejected(): void
    {
        $this->authenticateAs('admin', ['ROLE_ADMIN']);
        self::assertTrue($this->access()->canEdit($this->vehicle()));
        self::assertSame(0, $this->countVisibleWaybills());
        self::assertSame(0, $this->countVisibleWaybills('first'));

        $this->authenticateAs('first', ['ROLE_ADMIN']);
        $this->commands->execute(new EnterVehicleCommand($this->vehicleId));
        $this->em->clear();
        self::assertSame(2, $this->countVisibleWaybills());
        self::assertSame(2, $this->countVisibleWaybills('second'));

        static::getContainer()->get(TokenStorageInterface::class)->setToken(null);
        self::assertFalse($this->access()->canEdit($this->vehicle()));
        $this->expectException(ForbiddenException::class);
        $this->countVisibleWaybills();
    }

    public function test_saving_a_draft_after_the_driver_leaves_is_rejected_before_writing(): void
    {
        $this->authenticateAs('first');
        $this->commands->execute(new EnterVehicleCommand($this->vehicleId));
        $this->em->clear();
        $vehicle = $this->vehicle();
        $driver = $vehicle->driver();
        self::assertInstanceOf(DriverProfile::class, $driver);
        $id = Uuid::v7();
        $draft = Waybill::createDraft($id, 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-28')));

        $this->commands->execute(new LeaveVehicleCommand($this->vehicleId));
        $this->em->clear();
        try {
            static::getContainer()->get(WaybillRepositoryInterface::class)->add($draft);
            self::fail('Черновик без водителя не должен сохраняться.');
        } catch (AppException $exception) {
            self::assertStringContainsString('без водителя автомобиля', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM waybills_waybill WHERE id = ?', [(string) $id]));
    }

    public function test_current_driver_vehicle_filter_uses_authenticated_owner_not_supplied_owner(): void
    {
        $this->authenticateAs('first');
        $this->commands->execute(new EnterVehicleCommand($this->vehicleId));
        $this->em->clear();
        $result = $this->queries->execute(new GetPagedVehiclesQuery(new VehicleFilter(driverOwnerId: 'second'), currentDriverOnly: true));
        self::assertSame(1, $result->total);
        self::assertSame($this->vehicleId, $result->items[0]->id);

        $notMatching = $this->queries->execute(new GetPagedVehiclesQuery(new VehicleFilter(search: 'Неизвестная модель', driverOwnerId: 'first'), currentDriverOnly: true));
        self::assertSame(0, $notMatching->total);
        $this->authenticateAs('second', ['ROLE_ADMIN']);
        $other = $this->queries->execute(new GetPagedVehiclesQuery(new VehicleFilter(driverOwnerId: 'first'), currentDriverOnly: true));
        self::assertSame(0, $other->total);
    }

    private function countVisibleWaybills(?string $driverOwnerId = null): int
    {
        $result = $this->queries->execute(new GetPagedWaybillsQuery(new WaybillFilter($driverOwnerId, $this->vehicleId, Pager::fromPage(1, 10))));
        self::assertInstanceOf(PaginationResult::class, $result);

        return $result->total;
    }

    private function access(): WaybillAccessControl
    {
        return static::getContainer()->get(WaybillAccessControl::class);
    }

    private function vehicle(): Vehicle
    {
        return $this->em->find(Vehicle::class, $this->vehicleId) ?? throw new \LogicException('Автомобиль не найден.');
    }

    private function driver(string $owner): DriverProfile
    {
        return new DriverProfile(Uuid::v7(), $owner, new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), new Location('База'));
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
