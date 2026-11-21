<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Infrastructure\Database;

use App\Shared\Infrastructure\Database\Migrations\Version20261121000000;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000002;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000006;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class VehiclePostgresMappingTest extends KernelTestCase
{
    public function test_postgres_preserves_exact_fuel_and_instant(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        self::assertSame('test_db', $connection->getParams()['host'] ?? null);
        self::assertSame('app_test', $connection->getParams()['dbname'] ?? null);

        // Run the real migration SQL against connection-local temporary tables, never the existing tables.
        foreach ([Version20261121000000::class, Version20261121000002::class, Version20261121000006::class] as $migrationClass) {
            $migration = new $migrationClass($connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $query->getStatement());
                $connection->executeQuery($sql, $query->getParameters(), $query->getTypes());
            }
        }

        $driver = new DriverProfile(
            Uuid::v7(), 'owner', new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'),
            new Location('База'),
        );
        $instant = new \DateTimeImmutable('2026-01-02 03:04:05.123456+03:00');
        $vehicle = new Vehicle(
            Uuid::v7(), 'Модель', new RegistrationNumber('a123bc77'), $instant,
            new Location('Россия', 'Москва', 'База', '1'), 100, BigDecimal::of('30.00000000000000000001'),
            null, FuelType::Ai95, FuelType::Ai92, FuelType::Ai95,
        );
        $vehicle->enter($driver);
        $em->persist($driver);
        $em->persist($vehicle);
        $em->flush();
        $id = $vehicle->getId();
        $em->clear();

        $loaded = $em->find(Vehicle::class, $id);

        self::assertInstanceOf(Vehicle::class, $loaded);
        self::assertSame(100, $loaded->initialOdometer());
        self::assertSame(100, $loaded->currentOdometer());
        self::assertSame('30.00000000000000000001', (string) $loaded->initialFuelRemaining());
        self::assertSame('30.00000000000000000001', (string) $loaded->fuelRemaining());
        self::assertSame($instant->format('U.u'), $loaded->initialAt()->format('U.u'));
        self::assertSame([FuelType::Ai92, FuelType::Ai95], $loaded->getFuelTypes());
        self::assertTrue($loaded->isDrivenBy('owner'));

        foreach (['a000bc77', 'd123bc77', 'a123bc7', 'A123BC77'] as $invalidNumber) {
            $connection->beginTransaction();
            try {
                $connection->executeStatement('UPDATE waybills_vehicle SET registration_number = ? WHERE id = ?', [$invalidNumber, $id]);
                self::fail('База не должна принимать некорректный госномер.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            } finally {
                $connection->rollBack();
            }
        }

        $connection->close();
    }
}
