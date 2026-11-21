<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Infrastructure\Database;

use App\Shared\Infrastructure\Database\Migrations\Version20261121000000;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000001;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000004;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000005;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000006;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RouteLogMigrationTest extends KernelTestCase
{
    private Connection $connection;
    private string $vehicle;
    private string $otherVehicle;
    private string $draft;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(Connection::class);
        self::assertSame('test_db', $this->connection->getParams()['host'] ?? null);
        self::assertSame('app_test', $this->connection->getParams()['dbname'] ?? null);

        // Реальные миграции работают только с таблицами этой сессии PostgreSQL.
        foreach ([Version20261121000000::class, Version20261121000001::class, Version20261121000004::class] as $migrationClass) {
            $this->apply($migrationClass);
        }
        $this->apply(Version20261121000004::class);
        $this->apply(Version20261121000005::class);
        $this->apply(Version20261121000005::class);
        $this->apply(Version20261121000006::class);

        $this->vehicle = $this->insertVehicle('a123bc77');
        $this->otherVehicle = $this->insertVehicle('b123bc77');
        $this->draft = $this->insertDraft($this->vehicle);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        parent::tearDown();
    }

    public function test_chain_preserves_destination_distance_time_and_draft(): void
    {
        $first = $this->insertTrip($this->vehicle, null, null, 'manual', 1, 'Склад');
        $second = $this->insertTrip($this->vehicle, $first, $this->draft, 'automatic', 2);
        $row = $this->connection->fetchAssociative('SELECT * FROM waybills_route_log WHERE id = ?', [$second]);

        self::assertIsArray($row);
        self::assertSame($first, $row['previous_id']);
        self::assertSame($this->draft, $row['draft_waybill_id']);
        self::assertSame('automatic', $row['origin']);
        self::assertSame(2, (int) $row['distance']);
        self::assertSame((new \DateTimeImmutable('2026-10-01 12:00:00.123456+03:00'))->format('U.u'), (new \DateTimeImmutable((string) $row['occurred_at']))->format('U.u'));
        self::assertSame('Москва', json_decode((string) $row['location'], true, flags: JSON_THROW_ON_ERROR)['city']);
        self::assertSame('Склад', $this->connection->fetchOne('SELECT enterprise FROM waybills_route_log WHERE id = ?', [$first]));
        self::assertNull($row['enterprise']);
    }

    public function test_one_root_per_vehicle_and_no_branching(): void
    {
        $first = $this->insertTrip($this->vehicle);
        $this->assertRejected(fn () => $this->insertTrip($this->vehicle), '23505');
        $this->insertTrip($this->otherVehicle);
        $this->insertTrip($this->vehicle, $first);
        $this->assertRejected(fn () => $this->insertTrip($this->vehicle, $first), '23505');
    }

    public function test_previous_and_draft_must_belong_to_same_vehicle(): void
    {
        $first = $this->insertTrip($this->vehicle);
        $this->assertRejected(fn () => $this->insertTrip($this->otherVehicle, $first), '23503');
        $otherDraft = $this->insertDraft($this->otherVehicle);
        $this->assertRejected(fn () => $this->insertTrip($this->vehicle, $first, $otherDraft), '23503');
        $this->assertRejected(fn () => $this->insertTrip($this->vehicle, Uuid::v7()->toRfc4122()), '23503');
        $this->assertRejected(fn () => $this->insertTrip($this->vehicle, $first, Uuid::v7()->toRfc4122()), '23503');
        $this->assertRejected(fn () => $this->insertTrip(Uuid::v7()->toRfc4122()), '23503');
    }

    public function test_self_reference_is_rejected(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->assertRejected(fn () => $this->insertTrip($this->vehicle, $id, id: $id), '23514');
    }

    public function test_distance_and_origin_constraints(): void
    {
        foreach ([0, -1] as $distance) {
            $this->assertRejected(fn () => $this->insertTrip($this->vehicle, distance: $distance), '23514');
        }
        foreach (['1.5', 'Infinity', '-Infinity', 'NaN'] as $distance) {
            $this->assertRejected(fn () => $this->insertTrip($this->vehicle, distance: $distance), '22P02');
        }
        $this->assertRejected(fn () => $this->insertTrip($this->vehicle, origin: 'unknown'), '23514');
    }

    public function test_migration_refuses_to_round_existing_fractional_distance(): void
    {
        $this->connection->executeStatement('ALTER TABLE waybills_route_log ALTER COLUMN distance TYPE DOUBLE PRECISION');
        $trip = $this->insertTrip($this->vehicle, distance: 1.5);

        $this->assertRejected(fn () => $this->apply(Version20261121000005::class), 'P0001');
        self::assertSame(1.5, (float) $this->connection->fetchOne('SELECT distance FROM waybills_route_log WHERE id = ?', [$trip]));
    }

    public function test_infinite_dates_are_rejected(): void
    {
        foreach (['infinity', '-infinity'] as $date) {
            $this->assertRejected(fn () => $this->insertTrip($this->vehicle, date: $date), '23514');
        }
    }

    public function test_deferred_unique_constraint_permits_atomic_chain_rewrite(): void
    {
        $first = $this->insertTrip($this->vehicle);
        $last = $this->insertTrip($this->vehicle, $first, $this->draft);
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('SET CONSTRAINTS uniq_waybills_route_log_previous DEFERRED');
            $middle = $this->insertTrip($this->vehicle, $first, $this->draft);
            $this->connection->executeStatement('UPDATE waybills_route_log SET previous_id = ? WHERE id = ?', [$middle, $last]);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        self::assertSame($middle, $this->connection->fetchOne('SELECT previous_id FROM waybills_route_log WHERE id = ?', [$last]));
        self::assertSame($first, $this->connection->fetchOne('SELECT previous_id FROM waybills_route_log WHERE id = ?', [$middle]));
    }

    public function test_deferred_constraint_rejects_unresolved_branch(): void
    {
        $first = $this->insertTrip($this->vehicle);
        $this->insertTrip($this->vehicle, $first, $this->draft);

        $this->assertRejected(function () use ($first): void {
            $this->connection->executeStatement('SET CONSTRAINTS uniq_waybills_route_log_previous DEFERRED');
            $this->insertTrip($this->vehicle, $first, $this->draft);
            $this->connection->executeStatement('SET CONSTRAINTS uniq_waybills_route_log_previous IMMEDIATE');
        }, '23505');

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
    }

    public function test_referenced_predecessor_and_draft_cannot_be_deleted(): void
    {
        $first = $this->insertTrip($this->vehicle);
        $this->insertTrip($this->vehicle, $first, $this->draft);

        $this->assertRejected(fn () => $this->connection->executeStatement('DELETE FROM waybills_route_log WHERE id = ?', [$first]), '23503');
        $this->assertRejected(fn () => $this->connection->executeStatement('DELETE FROM waybills_waybill WHERE id = ?', [$this->draft]), '23503');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
    }

    /** @param class-string<\Doctrine\Migrations\AbstractMigration> $migrationClass */
    private function apply(string $migrationClass): void
    {
        $migration = new $migrationClass($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $query->getStatement());
            $this->connection->executeStatement($sql, $query->getParameters(), $query->getTypes());
        }
    }

    private function insertVehicle(string $registration): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO waybills_vehicle (id, model, registration_number, fuel_types, default_fuel_type, initial_at, initial_location, initial_odometer, initial_fuel_remaining, current_odometer, fuel_remaining)
            VALUES (?, 'Модель', ?, '["АИ-95"]', 'АИ-95', '2026-01-01 00:00:00+00', '{}', 0, 0, 0, 0)
            SQL, [$id, $registration]);

        return $id;
    }

    private function insertDraft(string $vehicle): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement("INSERT INTO waybills_waybill (id, owner_id, vehicle_id, period_from, period_to, locked, snapshot) VALUES (?, 'owner', ?, '2026-10-01', '2026-10-31', false, '{}')", [$id, $vehicle]);

        return $id;
    }

    private function insertTrip(string $vehicle, ?string $previous = null, ?string $draft = null, string $origin = 'manual', int|float|string $distance = 1, ?string $enterprise = null, ?string $id = null, string $date = '2026-10-01 12:00:00.123456+03'): string
    {
        $id ??= Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO waybills_route_log (id, vehicle_id, previous_id, occurred_at, location, enterprise, distance, origin, draft_waybill_id)
            VALUES (?, ?, ?, ?, '{"country":"Россия","city":"Москва","street":"Лесная","house":"1"}', ?, ?, ?, ?)
            SQL, [$id, $vehicle, $previous, $date, $enterprise, (string) $distance, $origin, $draft]);

        return $id;
    }

    private function assertRejected(callable $operation, string $sqlState): void
    {
        $this->connection->beginTransaction();
        try {
            $operation();
            self::fail('База должна отклонить некорректную поездку.');
        } catch (DriverException $exception) {
            self::assertSame($sqlState, $exception->getSQLState());
        } finally {
            $this->connection->rollBack();
        }
    }
}
