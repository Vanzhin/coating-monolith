<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Infrastructure\Database;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class VehicleMappingTest extends KernelTestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $configuration = static::getContainer()->get(EntityManagerInterface::class)->getConfiguration();
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE waybills_driver_profile (id TEXT PRIMARY KEY, owner_id TEXT NOT NULL UNIQUE, driver_details TEXT NOT NULL, route_settings TEXT NOT NULL)');
        // SQLite NUMERIC converts decimal strings to floats. TEXT preserves them as PostgreSQL NUMERIC does through PDO.
        $connection->executeStatement('CREATE TABLE waybills_vehicle (id TEXT PRIMARY KEY, model TEXT NOT NULL, registration_number TEXT NOT NULL UNIQUE, fuel_types TEXT NOT NULL, default_fuel_type TEXT NOT NULL, initial_at TEXT NOT NULL, initial_location TEXT NOT NULL, initial_odometer INTEGER NOT NULL, initial_fuel_remaining TEXT NOT NULL, current_odometer INTEGER NOT NULL, fuel_remaining TEXT NOT NULL, consumption_rate TEXT DEFAULT NULL, driver_id TEXT DEFAULT NULL UNIQUE REFERENCES waybills_driver_profile(id))');
        $this->em = new EntityManager($connection, $configuration);
    }

    protected function tearDown(): void
    {
        $this->em->close();
        $this->em->getConnection()->close();
        parent::tearDown();
    }

    public function test_vehicle_and_its_driver_round_trip_through_doctrine(): void
    {
        $driver = $this->driver();
        $vehicle = $this->vehicle('a123bc77');
        $vehicle->enter($driver);
        $vehicle->approveRoute(new Route($vehicle), null, BigDecimal::of('12.00000000000000000001'));
        $id = $vehicle->getId();
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->find(Vehicle::class, $id);

        self::assertInstanceOf(Vehicle::class, $loaded);
        self::assertSame('Модель', $loaded->getModel());
        self::assertSame('a123bc77', $loaded->getRegistrationNumber()->value());
        self::assertSame([FuelType::Ai92, FuelType::Ai95], $loaded->getFuelTypes());
        self::assertSame(FuelType::Ai95, $loaded->getDefaultFuelType());
        self::assertSame('2026-01-02 03:04:05.123456+03:00', $loaded->initialAt()->format('Y-m-d H:i:s.uP'));
        self::assertTrue($loaded->initialLocation()->equals(new Location('Россия', 'Москва', 'База', '1')));
        self::assertSame(100, $loaded->initialOdometer());
        self::assertSame(100, $loaded->currentOdometer());
        self::assertSame('30.00000000000000000001', (string) $loaded->initialFuelRemaining());
        self::assertSame('12.00000000000000000001', (string) $loaded->fuelRemaining());
        self::assertSame(8.5, $loaded->getConsumptionRate()?->value());
        self::assertSame($driver->getId(), $loaded->driver()?->getId());
        self::assertTrue($loaded->isDrivenBy('owner'));

        $loaded->leave($loaded->driver());
        $this->em->flush();
        $this->em->clear();
        self::assertNull($this->em->find(Vehicle::class, $id)?->driver());
    }

    public function test_database_rejects_the_same_driver_in_two_vehicles(): void
    {
        $driver = $this->driver();
        $first = $this->vehicle('a123bc77');
        $second = $this->vehicle('a124bc77');
        $first->enter($driver);
        $second->enter($driver);
        $this->em->persist($driver);
        $this->em->persist($first);
        $this->em->persist($second);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    private function driver(): DriverProfile
    {
        return new DriverProfile(
            Uuid::v7(), 'owner',
            new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'),
            new Location('База'),
        );
    }

    private function vehicle(string $registration): Vehicle
    {
        return new Vehicle(
            Uuid::v7(), 'Модель', new RegistrationNumber($registration),
            new \DateTimeImmutable('2026-01-02 03:04:05.123456+03:00'),
            new Location('Россия', 'Москва', 'База', '1'), 100,
            BigDecimal::of('30.00000000000000000001'), new PositiveNumber(8.5), FuelType::Ai95,
            FuelType::Ai92, FuelType::Ai95,
        );
    }
}
