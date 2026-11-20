<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Application\UseCase;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Aggregate\ValueObject\Email;
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
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\UseCase\Command\EnterVehicle\EnterVehicleCommand;
use App\Waybills\Application\UseCase\Command\LeaveVehicle\LeaveVehicleCommand;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Repository\WaybillRepositoryInterface;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

final class VehicleOccupancyUseCasesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandBusInterface $commands;
    private string $firstVehicleId;
    private string $secondVehicleId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->commands = static::getContainer()->get(CommandBusInterface::class);
        $connection = $this->em->getConnection();
        self::assertSame('test_db', $connection->getParams()['host'] ?? null);
        self::assertSame('app_test', $connection->getParams()['dbname'] ?? null);

        // Use the migration schema in temporary tables, without touching existing data.
        foreach ([Version20261121000000::class, Version20261121000001::class, Version20261121000002::class, Version20261121000003::class, Version20261121000004::class, Version20261121000005::class, Version20261121000006::class, Version20261121000007::class, Version20261121000008::class, Version20261121000009::class, Version20261121000010::class] as $migrationClass) {
            $migration = new $migrationClass($connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $query->getStatement());
                $connection->executeQuery($sql, $query->getParameters(), $query->getTypes());
            }
        }

        $first = $this->vehicle('a123bc77');
        $second = $this->vehicle('a124bc77');
        $this->firstVehicleId = $first->getId();
        $this->secondVehicleId = $second->getId();
        $this->em->persist($this->driver('first'));
        $this->em->persist($this->driver('second'));
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
        parent::tearDown();
    }

    public function test_enter_and_leave_persist_without_changing_a_draft(): void
    {
        $connection = $this->em->getConnection();
        $this->authenticateAs('first');
        $this->commands->execute(new EnterVehicleCommand($this->firstVehicleId));
        $this->em->clear();
        $vehicle = $this->em->find(Vehicle::class, $this->firstVehicleId);
        self::assertInstanceOf(Vehicle::class, $vehicle);
        $sheet = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        static::getContainer()->get(WaybillRepositoryInterface::class)->add($sheet);
        $draft = $connection->fetchAssociative('SELECT * FROM waybills_waybill WHERE id = ?', [$sheet->getId()]);
        self::assertTrue($vehicle->isDrivenBy('first'));

        $this->commands->execute(new LeaveVehicleCommand($this->firstVehicleId));
        $this->em->clear();
        self::assertNull($this->em->find(Vehicle::class, $this->firstVehicleId)?->driver());
        self::assertSame($draft, $connection->fetchAssociative('SELECT * FROM waybills_waybill WHERE id = ?', [$sheet->getId()]));

        $this->authenticateAs('second');
        $this->commands->execute(new EnterVehicleCommand($this->firstVehicleId));
        $this->em->clear();
        self::assertTrue($this->em->find(Vehicle::class, $this->firstVehicleId)?->isDrivenBy('second'));
        self::assertSame($draft, $connection->fetchAssociative('SELECT * FROM waybills_waybill WHERE id = ?', [$sheet->getId()]));
    }

    public function test_another_driver_cannot_enter_an_occupied_vehicle(): void
    {
        $this->authenticateAs('first');
        $this->commands->execute(new EnterVehicleCommand($this->firstVehicleId));
        $this->authenticateAs('second');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('В автомобиле уже есть водитель.');
        $this->commands->execute(new EnterVehicleCommand($this->firstVehicleId));
    }

    public function test_driver_cannot_enter_another_vehicle(): void
    {
        $this->authenticateAs('first');
        $this->commands->execute(new EnterVehicleCommand($this->firstVehicleId));

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Водитель уже находится в другом автомобиле.');
        $this->commands->execute(new EnterVehicleCommand($this->secondVehicleId));
    }

    public function test_another_user_cannot_remove_the_driver(): void
    {
        $this->authenticateAs('first');
        $this->commands->execute(new EnterVehicleCommand($this->firstVehicleId));
        $this->authenticateAs('second');

        $this->expectException(ForbiddenException::class);
        $this->commands->execute(new LeaveVehicleCommand($this->firstVehicleId));
    }

    public function test_user_without_a_profile_cannot_enter(): void
    {
        $this->authenticateAs('no-profile');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Карточка водителя не найдена.');
        $this->commands->execute(new EnterVehicleCommand($this->firstVehicleId));
    }

    public function test_anonymous_user_cannot_enter(): void
    {
        static::getContainer()->get(TokenStorageInterface::class)->setToken(null);

        $this->expectException(ForbiddenException::class);
        $this->commands->execute(new EnterVehicleCommand($this->firstVehicleId));
    }

    public function test_missing_vehicle_is_rejected(): void
    {
        $this->authenticateAs('first');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Автомобиль не найден.');
        $this->commands->execute(new EnterVehicleCommand((string) Uuid::v7()));
    }

    private function vehicle(string $number): Vehicle
    {
        return new Vehicle(
            Uuid::v7(), 'Модель', new RegistrationNumber($number),
            new \DateTimeImmutable('2026-01-01'), new Location('База'), 100, BigDecimal::of('30'),
        );
    }

    private function driver(string $owner): DriverProfile
    {
        return new DriverProfile(
            Uuid::v7(), $owner, new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'),
            new Location('База'),
        );
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
