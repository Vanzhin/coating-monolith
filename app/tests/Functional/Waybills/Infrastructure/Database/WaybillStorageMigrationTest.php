<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Infrastructure\Database;

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
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillStorageMigrationTest extends KernelTestCase
{
    private Connection $connection;
    private string $vehicleId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(Connection::class);
        self::assertSame('test_db', $this->connection->getParams()['host'] ?? null);
        self::assertSame('app_test', $this->connection->getParams()['dbname'] ?? null);
        foreach ([Version20261121000000::class, Version20261121000001::class, Version20261121000002::class, Version20261121000003::class, Version20261121000004::class, Version20261121000005::class, Version20261121000006::class] as $migrationClass) {
            $this->apply($migrationClass);
        }
        self::assertNotNull($this->connection->fetchOne("SELECT to_regclass('pg_temp.waybills_waybill')"));
        self::assertNotNull($this->connection->fetchOne("SELECT to_regclass('pg_temp.waybills_route_log')"));
        $this->vehicleId = (string) Uuid::v7();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO waybills_vehicle (id, model, registration_number, fuel_types, default_fuel_type, initial_at, initial_location, initial_odometer, initial_fuel_remaining, current_odometer, fuel_remaining)
            VALUES (?, 'Модель', 'a123bc77', '["АИ-95"]', 'АИ-95', '2026-01-01 00:00:00+00', '{}', 0, 0, 0, 0)
            SQL, [$this->vehicleId]);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        parent::tearDown();
    }

    public function test_empty_old_schema_migrates_and_repeated_migration_preserves_new_data(): void
    {
        $this->apply(Version20261121000007::class);
        $id = (string) Uuid::v7();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO waybills_waybill (id, vehicle_id, number, period_from, period_to, locked, odometer_start, fuel_start)
            VALUES (?, ?, 1, '2026-02-01T00:30:00.123456+03:00|+03:00', '2026-01-31T22:00:00.654321+00:00|+00:00', false, 0, 0)
            SQL, [$id, $this->vehicleId]);
        $tripId = (string) Uuid::v7();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO waybills_route_log (id, vehicle_id, occurred_at, location, distance, origin, draft_waybill_id)
            VALUES (?, ?, '2026-01-31T22:00:00.000000+00:00|UTC', '{}', 1, 'manual', ?)
            SQL, [$tripId, $this->vehicleId, $id]);

        $this->apply(Version20261121000007::class);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_waybill WHERE id = ?', [$id]));
        self::assertSame($id, $this->connection->fetchOne('SELECT draft_waybill_id FROM waybills_route_log WHERE id = ?', [$tripId]));
        self::assertFalse($this->hasColumn('waybills_waybill', 'owner_id'));
        self::assertFalse($this->hasColumn('waybills_waybill', 'snapshot'));
        self::assertTrue($this->hasColumn('waybills_waybill', 'approved_driver'));
    }

    public function test_old_waybill_blocks_migration_without_losing_data(): void
    {
        $id = (string) Uuid::v7();
        $this->connection->executeStatement("INSERT INTO waybills_waybill (id, owner_id, vehicle_id, period_from, period_to, locked, snapshot) VALUES (?, 'owner', ?, '2026-01-01', '2026-01-31', false, '{\"keep\":true}')", [$id, $this->vehicleId]);

        $this->assertMigrationRejected();

        self::assertTrue($this->hasColumn('waybills_waybill', 'snapshot'));
        self::assertTrue($this->hasColumn('waybills_waybill', 'owner_id'));
        self::assertSame('true', $this->connection->fetchOne("SELECT snapshot->>'keep' FROM waybills_waybill WHERE id = ?", [$id]));
    }

    public function test_migration_keeps_chain_constraints_and_draft_indexes(): void
    {
        $this->apply(Version20261121000007::class);
        $constraints = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT conname, contype, condeferrable, pg_get_constraintdef(oid) AS definition
            FROM pg_constraint
            WHERE conrelid = 'pg_temp.waybills_route_log'::regclass
              AND conname IN ('uniq_waybills_route_log_previous', 'fk_waybills_route_log_previous', 'fk_waybills_route_log_draft')
            SQL);
        $byName = array_column($constraints, null, 'conname');
        self::assertCount(3, $byName);
        foreach ($byName as $constraint) {
            self::assertTrue((bool) $constraint['condeferrable']);
        }
        self::assertStringContainsString('UNIQUE NULLS NOT DISTINCT (vehicle_id, previous_id)', $byName['uniq_waybills_route_log_previous']['definition']);
        self::assertStringContainsString('FOREIGN KEY (previous_id, vehicle_id)', $byName['fk_waybills_route_log_previous']['definition']);
        self::assertStringContainsString('FOREIGN KEY (draft_waybill_id, vehicle_id)', $byName['fk_waybills_route_log_draft']['definition']);
        self::assertNotNull($this->connection->fetchOne("SELECT to_regclass('pg_temp.uniq_waybills_vehicle_draft')"));
        self::assertNotNull($this->connection->fetchOne("SELECT to_regclass('pg_temp.idx_waybills_route_log_draft')"));
    }

    public function test_old_trip_without_original_timezone_blocks_migration(): void
    {
        $id = (string) Uuid::v7();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO waybills_route_log (id, vehicle_id, occurred_at, location, distance, origin)
            VALUES (?, ?, '2026-01-10 12:00:00+03:00', '{}', 1, 'manual')
            SQL, [$id, $this->vehicleId]);

        $this->assertMigrationRejected();

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log WHERE id = ?', [$id]));
        self::assertTrue($this->hasColumn('waybills_waybill', 'snapshot'));
    }

    public function test_migration_is_explicitly_irreversible(): void
    {
        $this->expectException(IrreversibleMigration::class);
        (new Version20261121000007($this->connection, new NullLogger()))->down(new Schema());
    }

    public function test_fuel_types_column_is_removed_without_changing_the_recorded_fuel(): void
    {
        $this->apply(Version20261121000007::class);
        $id = (string) Uuid::v7();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO waybills_waybill (id, vehicle_id, number, period_from, period_to, locked, odometer_start, fuel_start, fuel)
            VALUES (?, ?, 1, '2026-01-01T00:00:00+00:00|UTC', '2026-01-31T00:00:00+00:00|UTC', false, 0, 0, '{"initial":0,"remaining":null,"type":"АИ-95","refuelings":null}')
            SQL, [$id, $this->vehicleId]);

        $this->apply(Version20261121000008::class);
        $this->apply(Version20261121000008::class);

        self::assertFalse($this->hasColumn('waybills_waybill', 'approved_fuel_types'));
        self::assertSame('АИ-95', $this->connection->fetchOne("SELECT fuel->>'type' FROM waybills_waybill WHERE id = ?", [$id]));
    }

    public function test_fuel_types_migration_refuses_to_discard_existing_snapshot(): void
    {
        $this->apply(Version20261121000007::class);
        $id = (string) Uuid::v7();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO waybills_waybill (id, vehicle_id, number, period_from, period_to, locked, odometer_start, odometer_end, fuel_start, fuel, route_present, approved_driver, approved_base, approved_vehicle_model, approved_registration_number, approved_fuel_types)
            VALUES (?, ?, 1, '2026-01-01T00:00:00+00:00|UTC', '2026-01-31T00:00:00+00:00|UTC', true, 0, 0, 0,
                '{"initial":0,"remaining":0,"type":"АИ-95","refuelings":{"total":0,"entries":[]}}', true, '{}', '{}', 'Модель', 'a123bc77', '["АИ-95"]')
            SQL, [$id, $this->vehicleId]);
        try {
            $this->apply(Version20261121000008::class);
            self::fail('Сохранённый снимок нельзя удалять без отдельного решения.');
        } catch (DriverException $exception) {
            self::assertSame('P0001', $exception->getSQLState());
        }
        self::assertTrue($this->hasColumn('waybills_waybill', 'approved_fuel_types'));
        self::assertSame('АИ-95', $this->connection->fetchOne('SELECT approved_fuel_types->>0 FROM waybills_waybill WHERE id = ?', [$id]));
        self::assertSame('АИ-95', $this->connection->fetchOne("SELECT fuel->>'type' FROM waybills_waybill WHERE id = ?", [$id]));
    }

    public function test_fuel_journal_constraints_preserve_vehicle_and_draft_order(): void
    {
        $this->apply(Version20261121000007::class);
        $this->apply(Version20261121000008::class);
        $this->apply(Version20261121000009::class);
        $otherVehicle = (string) Uuid::v7();
        $draft = (string) Uuid::v7();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO waybills_vehicle (id, model, registration_number, fuel_types, default_fuel_type, initial_at, initial_location, initial_odometer, initial_fuel_remaining, current_odometer, fuel_remaining)
            VALUES (?, 'Другой', 'b124cc77', '["АИ-95"]', 'АИ-95', '2026-01-01 00:00:00+00', '{}', 0, 0, 0, 0)
            SQL, [$otherVehicle]);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO waybills_waybill (id, vehicle_id, number, period_from, period_to, locked, odometer_start, fuel_start)
            VALUES (?, ?, 1, '2026-01-01T00:00:00+00:00|UTC', '2026-01-31T00:00:00+00:00|UTC', false, 0, 0)
            SQL, [$draft, $this->vehicleId]);
        $insert = <<<'SQL'
            INSERT INTO waybills_fuel_operation (id, vehicle_id, occurred_at, fuel_type, amount, type, draft_waybill_id, position)
            VALUES (?, ?, '2026-01-10T10:00:00.000000+00:00|UTC', 'АИ-95', ?, ?, ?, ?)
            SQL;
        $id = (string) Uuid::v7();
        $this->connection->executeStatement($insert, [$id, $this->vehicleId, '0.12345678901234567890', 'refueling', $draft, 0]);
        foreach ([
            ['0', 'refueling', $this->vehicleId, $draft, 1, '23514'],
            ['NaN', 'refueling', $this->vehicleId, $draft, 1, '23514'],
            ['1', 'refueling', $otherVehicle, $draft, 1, '23503'],
            ['2', 'refueling', $this->vehicleId, $draft, 0, '23505'],
            ['-1', 'reconciliation', $this->vehicleId, $draft, 1, '23514'],
        ] as [$amount, $type, $vehicle, $draftId, $position, $sqlState]) {
            $this->connection->beginTransaction();
            try {
                $this->connection->executeStatement($insert, [(string) Uuid::v7(), $vehicle, $amount, $type, $draftId, $position]);
                self::fail('Некорректная топливная операция должна быть отклонена.');
            } catch (DriverException $exception) {
                self::assertSame($sqlState, $exception->getSQLState());
            } finally {
                $this->connection->rollBack();
            }
        }
        $this->apply(Version20261121000009::class);
        self::assertSame('0.12345678901234567890', $this->connection->fetchOne('SELECT amount::text FROM waybills_fuel_operation WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_fuel_operation'));
    }

    public function test_approval_does_not_require_legacy_refuelings_and_allows_one_reconciliation(): void
    {
        $this->apply(Version20261121000007::class);
        $this->apply(Version20261121000008::class);
        $this->apply(Version20261121000009::class);
        $this->apply(Version20261121000010::class);
        $id = (string) Uuid::v7();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO waybills_waybill (id, vehicle_id, number, period_from, period_to, locked, odometer_start, odometer_end, fuel_start, fuel, route_present, approved_driver, approved_base, approved_vehicle_model, approved_registration_number)
            VALUES (?, ?, 1, '2026-01-01T00:00:00+00:00|UTC', '2026-01-31T00:00:00+00:00|UTC', true, 0, 0, 0,
                '{"initial":0,"remaining":0,"type":"АИ-95","refuelings":null}', true, '{}', '{}', 'Модель', 'a123bc77')
            SQL, [$id, $this->vehicleId]);
        $insert = <<<'SQL'
            INSERT INTO waybills_fuel_operation (id, vehicle_id, occurred_at, fuel_type, amount, type, waybill_id)
            VALUES (?, ?, '2026-01-31T00:00:00.000000+00:00|UTC', 'АИ-95', 0, 'reconciliation', ?)
            SQL;
        $this->connection->executeStatement($insert, [(string) Uuid::v7(), $this->vehicleId, $id]);
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement($insert, [(string) Uuid::v7(), $this->vehicleId, $id]);
            self::fail('У утверждённого листа может быть только одна сверка.');
        } catch (DriverException $exception) {
            self::assertSame('23505', $exception->getSQLState());
        } finally {
            $this->connection->rollBack();
        }

        $this->apply(Version20261121000010::class);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_fuel_operation'));
    }

    private function assertMigrationRejected(): void
    {
        try {
            $this->apply(Version20261121000007::class);
            self::fail('Миграция не должна терять существующие данные.');
        } catch (DriverException $exception) {
            self::assertSame('P0001', $exception->getSQLState());
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = CAST(? AS regclass) AND attname = ? AND NOT attisdropped)',
            ['pg_temp.'.$table, $column],
        );
    }

    /** @param class-string<AbstractMigration> $migrationClass */
    private function apply(string $migrationClass): void
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
