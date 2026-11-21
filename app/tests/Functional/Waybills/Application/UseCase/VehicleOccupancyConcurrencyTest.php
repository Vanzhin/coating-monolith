<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Application\UseCase;

use App\Shared\Infrastructure\Database\Migrations\Version20261121000000;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000002;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class VehicleOccupancyConcurrencyTest extends KernelTestCase
{
    public function test_locks_and_unique_constraint_prevent_conflicting_occupancy(): void
    {
        self::bootKernel();
        $params = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->getParams();
        self::assertSame('test_db', $params['host'] ?? null);
        self::assertSame('app_test', $params['dbname'] ?? null);
        // Independent connections: the test bundle wraps the container connection in a rollback-only transaction.
        $first = DriverManager::getConnection($params);
        $second = DriverManager::getConnection($params);
        $schema = 'waybill_test_'.bin2hex(random_bytes(8));
        $first->executeStatement('CREATE SCHEMA '.$schema);

        try {
            $first->executeStatement('SET search_path TO '.$schema);
            $second->executeStatement('SET search_path TO '.$schema);
            foreach ([Version20261121000000::class, Version20261121000002::class] as $migrationClass) {
                $migration = new $migrationClass($first, new NullLogger());
                $migration->up(new Schema());
                foreach ($migration->getSql() as $query) {
                    $first->executeQuery($query->getStatement(), $query->getParameters(), $query->getTypes());
                }
            }
            self::assertSame($schema, $first->fetchOne('SELECT current_schema()'));
            self::assertSame($schema, $second->fetchOne('SELECT current_schema()'));
            self::assertNotNull($second->fetchOne('SELECT to_regclass(?)', [$schema.'.waybills_vehicle']));

            $driver = (string) Uuid::v7();
            $carA = (string) Uuid::v7();
            $carB = (string) Uuid::v7();
            $first->executeStatement("INSERT INTO waybills_driver_profile (id, owner_id, driver_details, route_settings) VALUES (?, 'owner', '{}', '{}')", [$driver]);
            foreach ([$carA => 'a123bc77', $carB => 'a124bc77'] as $car => $number) {
                $first->executeStatement("INSERT INTO waybills_vehicle (id, model, registration_number, fuel_types, default_fuel_type, initial_at, initial_location, initial_odometer, initial_fuel_remaining, current_odometer, fuel_remaining) VALUES (?, 'Модель', ?, '[\"АИ-95\"]', 'АИ-95', '2026-01-01T00:00:00Z', '{}', 100, 30, 100, 30)", [$car, $number]);
            }

            $first->beginTransaction();
            $first->fetchOne('SELECT id FROM waybills_vehicle WHERE id = ? FOR UPDATE', [$carA]);
            $second->beginTransaction();
            $second->executeStatement("SET LOCAL lock_timeout = '200ms'");
            try {
                $second->fetchOne('SELECT id FROM waybills_vehicle WHERE id = ? FOR UPDATE', [$carA]);
                self::fail('Одновременно заблокировать один автомобиль нельзя.');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }
            $second->rollBack();
            $first->executeStatement('UPDATE waybills_vehicle SET driver_id = ? WHERE id = ?', [$driver, $carA]);

            $second->beginTransaction();
            $second->executeStatement("SET LOCAL lock_timeout = '200ms'");
            try {
                $second->executeStatement('UPDATE waybills_vehicle SET driver_id = ? WHERE id = ?', [$driver, $carB]);
                self::fail('Конкурирующая посадка того же водителя должна ждать завершения первой.');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }
            $second->rollBack();
            $first->commit();

            try {
                $second->executeStatement('UPDATE waybills_vehicle SET driver_id = ? WHERE id = ?', [$driver, $carB]);
                self::fail('Водитель не может находиться в двух автомобилях.');
            } catch (UniqueConstraintViolationException $exception) {
                self::assertStringContainsString('uniq_waybills_vehicle_driver', $exception->getMessage());
            }
            self::assertSame($driver, $first->fetchOne('SELECT driver_id FROM waybills_vehicle WHERE id = ?', [$carA]));
            self::assertNull($first->fetchOne('SELECT driver_id FROM waybills_vehicle WHERE id = ?', [$carB]));
        } finally {
            if ($second->isTransactionActive()) {
                $second->rollBack();
            }
            if ($first->isTransactionActive()) {
                $first->rollBack();
            }
            $second->close();
            $first->executeStatement('SET search_path TO public');
            $first->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
            $first->close();
        }
    }
}
