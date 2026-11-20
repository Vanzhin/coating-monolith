<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Infrastructure\Database;

use App\Shared\Infrastructure\Database\Migrations\Version20261121000000;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000006;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class VehicleOdometerMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(Connection::class);
        self::assertSame('test_db', $this->connection->getParams()['host'] ?? null);
        self::assertSame('app_test', $this->connection->getParams()['dbname'] ?? null);
        $this->apply(Version20261121000000::class);
        self::assertNotNull($this->connection->fetchOne("SELECT to_regclass('pg_temp.waybills_vehicle')"));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        parent::tearDown();
    }

    public function test_integer_readings_survive_idempotent_migration(): void
    {
        $id = $this->insertVehicle('2147483648', '9007199254740992');
        $this->apply(Version20261121000006::class);
        $this->apply(Version20261121000006::class);

        $row = $this->connection->fetchAssociative('SELECT initial_odometer, current_odometer FROM waybills_vehicle WHERE id = ?', [$id]);
        self::assertIsArray($row);
        self::assertSame('2147483648', (string) $row['initial_odometer']);
        self::assertSame('9007199254740992', (string) $row['current_odometer']);
    }

    #[DataProvider('invalidReadings')]
    public function test_migration_refuses_to_change_invalid_readings(string $initial, string $current): void
    {
        $id = $this->insertVehicle($initial, $current);

        $this->connection->beginTransaction();
        try {
            $this->apply(Version20261121000006::class);
            self::fail('Миграция не должна округлять или преобразовывать некорректные показания.');
        } catch (DriverException $exception) {
            self::assertSame('P0001', $exception->getSQLState());
        } finally {
            $this->connection->rollBack();
        }

        $row = $this->connection->fetchAssociative('SELECT initial_odometer, current_odometer FROM waybills_vehicle WHERE id = ?', [$id]);
        self::assertIsArray($row);
        self::assertSame((float) $initial, (float) $row['initial_odometer']);
        self::assertSame((float) $current, (float) $row['current_odometer']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidReadings(): iterable
    {
        yield 'fractional initial' => ['1.5', '2'];
        yield 'fractional current' => ['1', '2.5'];
        yield 'initial exceeds bigint' => ['9223372036854775808', '2'];
        yield 'current exceeds bigint' => ['1', '9223372036854775808'];
    }

    public function test_migration_rejects_non_finite_readings(): void
    {
        $id = $this->insertVehicle('NaN', 'Infinity');

        $this->connection->beginTransaction();
        try {
            $this->apply(Version20261121000006::class);
            self::fail('Миграция не должна преобразовывать NaN и бесконечность.');
        } catch (DriverException $exception) {
            self::assertSame('P0001', $exception->getSQLState());
        } finally {
            $this->connection->rollBack();
        }

        $row = $this->connection->fetchAssociative('SELECT initial_odometer::text AS initial, current_odometer::text AS current FROM waybills_vehicle WHERE id = ?', [$id]);
        self::assertIsArray($row);
        self::assertSame('NaN', $row['initial']);
        self::assertSame('Infinity', $row['current']);
    }

    public function test_rollback_rejects_a_reading_that_may_lose_precision(): void
    {
        $id = $this->insertVehicle('1', '2');
        $this->apply(Version20261121000006::class);
        $this->connection->executeStatement('UPDATE waybills_vehicle SET current_odometer = 9007199254740993 WHERE id = ?', [$id]);

        $this->connection->beginTransaction();
        try {
            $this->apply(Version20261121000006::class, down: true);
            self::fail('Откат не должен терять точность целого показания.');
        } catch (DriverException $exception) {
            self::assertSame('P0001', $exception->getSQLState());
        } finally {
            $this->connection->rollBack();
        }

        self::assertSame('9007199254740993', (string) $this->connection->fetchOne('SELECT current_odometer FROM waybills_vehicle WHERE id = ?', [$id]));
    }

    /** @param class-string<AbstractMigration> $migrationClass */
    private function apply(string $migrationClass, bool $down = false): void
    {
        $migration = new $migrationClass($this->connection, new NullLogger());
        if ($down) {
            $migration->down(new Schema());
        } else {
            $migration->up(new Schema());
        }
        foreach ($migration->getSql() as $query) {
            $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $query->getStatement());
            $this->connection->executeStatement($sql, $query->getParameters(), $query->getTypes());
        }
    }

    private function insertVehicle(string $initial, string $current): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO waybills_vehicle (id, model, registration_number, fuel_types, default_fuel_type, initial_at, initial_location, initial_odometer, initial_fuel_remaining, current_odometer, fuel_remaining)
            VALUES (?, 'Модель', 'a123bc77', '["АИ-95"]', 'АИ-95', '2026-01-01 00:00:00+00', '{}', ?, 0, ?, 0)
            SQL, [$id, $initial, $current]);

        return $id;
    }
}
