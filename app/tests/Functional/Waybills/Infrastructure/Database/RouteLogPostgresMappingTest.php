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
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\EntityIdentityCollisionException;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Middleware;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RouteLogPostgresMappingTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManager $em;
    private DebugDataHolder $queries;

    protected function setUp(): void
    {
        self::bootKernel();
        $configuredEm = static::getContainer()->get(EntityManagerInterface::class);
        $params = $configuredEm->getConnection()->getParams();
        self::assertSame('test_db', $params['host'] ?? null);
        self::assertSame('app_test', $params['dbname'] ?? null);

        // Отдельное соединение: middleware считает SQL, а все таблицы живут только в этой сессии.
        $this->queries = new DebugDataHolder();
        $config = new Configuration();
        $config->setMiddlewares([new Middleware($this->queries, null)]);
        $this->connection = DriverManager::getConnection($params, $config);
        $this->em = new EntityManager($this->connection, $configuredEm->getConfiguration());

        // 00002 добавляет driver_id, необходимый существующему ORM-маппингу Vehicle.
        foreach ([Version20261121000000::class, Version20261121000001::class, Version20261121000002::class, Version20261121000003::class, Version20261121000004::class, Version20261121000005::class, Version20261121000006::class, Version20261121000007::class, Version20261121000008::class, Version20261121000009::class] as $migrationClass) {
            $this->applyTemporaryMigration($migrationClass);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
        parent::tearDown();
    }

    public function test_metadata_maps_entity_embedded_destination_and_not_draft(): void
    {
        $metadata = $this->em->getClassMetadata(RouteLog::class);
        self::assertSame('waybills_route_log', $metadata->getTableName());
        self::assertSame(['id'], $metadata->getIdentifierFieldNames());
        self::assertSame('uuid', $metadata->getTypeOfField('id'));
        self::assertSame('exact_datetime', $metadata->getTypeOfField('date'));
        self::assertSame('occurred_at', $metadata->getColumnName('date'));
        self::assertSame(RouteLogOrigin::class, $metadata->fieldMappings['origin']->enumType);
        self::assertSame(Destination::class, $metadata->embeddedClasses['destination']->class);
        self::assertSame('waybills_location', $metadata->getTypeOfField('destination.location'));
        self::assertSame('integer', $metadata->getTypeOfField('destination.distance'));
        self::assertSame('location', $metadata->getColumnName('destination.location'));
        self::assertSame('distance', $metadata->getColumnName('destination.distance'));
        self::assertSame('enterprise', $metadata->getColumnName('destination.enterprise'));
        self::assertSame(RouteLog::class, $metadata->getAssociationTargetClass('previous'));
        self::assertSame(Vehicle::class, $metadata->getAssociationTargetClass('vehicle'));
        self::assertNotContains('draft_waybill_id', $metadata->getColumnNames());
        self::assertFalse($metadata->hasAssociation('draft'));
        self::assertTrue((new \ReflectionProperty(RouteLog::class, 'id'))->isPrivateSet());
        foreach (['vehicle', 'previous', 'date', 'destination', 'origin'] as $property) {
            self::assertTrue((new \ReflectionProperty(RouteLog::class, $property))->isReadOnly());
        }
        self::assertTrue($this->em->getConfiguration()->isNativeLazyObjectsEnabled());
    }

    public function test_persist_and_reload_approved_chain_and_lazy_readonly_predecessor(): void
    {
        $vehicle = $this->vehicle();
        $firstDate = new \DateTimeImmutable('2026-10-01 12:00:00.123456+03:00');
        $secondDate = new \DateTimeImmutable('2026-10-02 14:15:16.987654-04:00');
        $first = $this->trip($vehicle, null, $firstDate, new Location('Россия', 'Москва', 'Лесная', '1'), 1, 'Склад', RouteLogOrigin::Manual);
        $second = $this->trip($vehicle, $first, $secondDate, new Location('Россия', 'Тверь', 'Мира', '12'), 2, null, RouteLogOrigin::Automatic);
        $this->em->persist($vehicle);
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();

        // Сохранение черновика не входит в шаг 2: ORM создаёт только утверждённые записи.
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log WHERE draft_waybill_id IS NULL'));
        $firstId = $first->id;
        $secondId = $second->id;
        $this->em->clear();
        $this->queries->reset();

        // Сначала потомок, затем id и остальные поля final readonly предшественника.
        $loaded = $this->em->find(RouteLog::class, $secondId);
        self::assertInstanceOf(RouteLog::class, $loaded);
        self::assertSame((string) $secondId, (string) $loaded->id);
        self::assertSame($secondDate->format('U.u'), $loaded->date->format('U.u'));
        self::assertSame(RouteLogOrigin::Automatic, $loaded->origin);
        self::assertSame(2, $loaded->distanceInKilometers());
        self::assertNull($loaded->destination->enterprise);
        self::assertSame(['country' => 'Россия', 'city' => 'Тверь', 'street' => 'Мира', 'house' => '12'], $loaded->destination->location->jsonSerialize());
        self::assertNotNull($loaded->previous);
        self::assertSame((string) $firstId, (string) $loaded->previous->id);
        self::assertSame($firstDate->format('U.u'), $loaded->previous->date->format('U.u'));
        self::assertSame($loaded->previous, $this->em->find(RouteLog::class, $firstId));
        self::assertSame($loaded->vehicle, $loaded->previous->vehicle);
        self::assertNull($loaded->previous->previous);
        self::assertSame(RouteLogOrigin::Manual, $loaded->previous->origin);
        self::assertSame(1, $loaded->previous->distanceInKilometers());
        self::assertSame('Склад', $loaded->previous->destination->enterprise);
        self::assertSame(['country' => 'Россия', 'city' => 'Москва', 'street' => 'Лесная', 'house' => '1'], $loaded->previous->destination->location->jsonSerialize());
        self::assertTrue($loaded->departure()->equals($loaded->previous->destination->location));
    }

    public function test_root_round_trip_without_a_predecessor(): void
    {
        $vehicle = $this->vehicle();
        $date = new \DateTimeImmutable('2026-10-01 12:00:00.123456+03:00');
        $trip = $this->trip($vehicle, null, $date, new Location('Россия', 'Москва', 'Лесная', '1'), 1, 'Склад', RouteLogOrigin::Manual);
        $this->em->persist($vehicle);
        $this->em->persist($trip);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->find(RouteLog::class, $trip->id);
        self::assertInstanceOf(RouteLog::class, $loaded);
        self::assertSame((string) $trip->id, (string) $loaded->id);
        self::assertSame($vehicle->getId(), $loaded->vehicle->getId());
        self::assertNull($loaded->previous);
        self::assertSame($date->format('U.u'), $loaded->date->format('U.u'));
        self::assertSame(RouteLogOrigin::Manual, $loaded->origin);
        self::assertSame(1, $loaded->distanceInKilometers());
        self::assertSame('Склад', $loaded->destination->enterprise);
        self::assertSame(['country' => 'Россия', 'city' => 'Москва', 'street' => 'Лесная', 'house' => '1'], $loaded->destination->location->jsonSerialize());
    }

    public function test_seeded_draft_reference_is_not_lost_on_load_and_flush(): void
    {
        $vehicle = $this->vehicle();
        $this->em->persist($vehicle);
        $this->em->flush();
        $draft = Uuid::v7()->toRfc4122();
        $id = Uuid::v7();
        $this->connection->executeStatement("INSERT INTO waybills_waybill (id, vehicle_id, number, period_from, period_to, locked, odometer_start, fuel_start) VALUES (?, ?, 1, '2026-10-01T00:00:00.000000+00:00|UTC', '2026-10-31T23:59:59.000000+00:00|UTC', false, 100, 20)", [$draft, $vehicle->getId()]);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO waybills_route_log (id, vehicle_id, previous_id, occurred_at, location, enterprise, distance, origin, draft_waybill_id)
            VALUES (?, ?, NULL, '2026-10-01T12:00:00.123456+03:00|+03:00', '{"country":"Россия","city":"Москва","street":"Лесная","house":"1"}', 'Склад', 1, 'manual', ?)
            SQL, [(string) $id, $vehicle->getId(), $draft]);
        $this->em->clear();

        $loaded = $this->em->find(RouteLog::class, $id);
        self::assertInstanceOf(RouteLog::class, $loaded);
        self::assertSame('Склад', $loaded->destination->enterprise);
        self::assertSame(1, $loaded->distanceInKilometers());
        self::assertSame(RouteLogOrigin::Manual, $loaded->origin);
        $this->em->flush();
        self::assertSame($draft, $this->connection->fetchOne('SELECT draft_waybill_id FROM waybills_route_log WHERE id = ?', [(string) $id]));
    }

    public function test_vehicle_load_does_not_load_history_and_segment_fetch_has_no_n_plus_one(): void
    {
        $vehicle = $this->vehicle();
        $this->em->persist($vehicle);
        $previous = null;
        $ids = [];
        for ($i = 1; $i <= 8; ++$i) {
            $previous = $this->trip($vehicle, $previous, new \DateTimeImmutable("2026-10-{$i} 12:00:00+00:00"), new Location('Россия', 'Город '.$i), 1, null, RouteLogOrigin::Manual);
            $this->em->persist($previous);
            $ids[] = (string) $previous->id;
        }
        $this->em->flush();
        $vehicleId = $vehicle->getId();
        $this->em->clear();
        $this->queries->reset();

        self::assertInstanceOf(Vehicle::class, $this->em->find(Vehicle::class, $vehicleId));
        self::assertCount(0, $this->em->getUnitOfWork()->getIdentityMap()[RouteLog::class] ?? []);
        $vehicleQueries = array_column($this->queries->getData()['default'] ?? [], 'sql');
        self::assertNotEmpty($vehicleQueries);
        foreach ($vehicleQueries as $sql) {
            self::assertStringNotContainsStringIgnoringCase('waybills_route_log', $sql);
        }

        $this->em->clear();
        $this->queries->reset();
        $segment = $this->em->createQuery('SELECT trip, predecessor FROM '.RouteLog::class.' trip LEFT JOIN trip.previous predecessor WHERE trip.id IN (:ids) ORDER BY trip.date')
            ->setParameter('ids', array_slice($ids, 5, 3))
            ->getResult();
        self::assertCount(3, $segment);
        foreach ($segment as $index => $trip) {
            self::assertSame($ids[$index + 5], (string) $trip->id);
            self::assertNotNull($trip->previous);
            self::assertSame($ids[$index + 4], (string) $trip->previous->id);
            self::assertSame('Город '.($index + 5), $trip->previous->destination->location->city);
        }
        $sql = array_column($this->queries->getData()['default'] ?? [], 'sql');
        self::assertCount(1, $sql, implode("\n", $sql));
        // Три поездки, один предшественник и максимум один неинициализированный ghost.
        self::assertLessThanOrEqual(5, count($this->em->getUnitOfWork()->getIdentityMap()[RouteLog::class] ?? []));
    }

    public function test_period_fetch_uses_chain_for_equal_times_and_loads_only_one_prior_trip(): void
    {
        $vehicle = $this->vehicle();
        $this->em->persist($vehicle);
        $previous = null;
        $ids = [];
        $dates = [
            '2026-09-28 12:00:00+00:00',
            '2026-09-30 12:00:00+00:00',
            '2026-10-01 15:00:00+03:00',
            '2026-10-01 08:00:00-04:00',
            '2026-10-01 12:00:00+00:00',
            '2026-10-03 12:00:00+00:00',
        ];
        foreach ($dates as $index => $date) {
            $previous = $this->trip($vehicle, $previous, new \DateTimeImmutable($date), new Location('Россия', 'Точка '.($index + 1)), 1, null, RouteLogOrigin::Manual);
            $this->em->persist($previous);
            $ids[] = (string) $previous->id;
        }
        $this->em->flush();
        $vehicleId = $vehicle->getId();
        $this->em->clear();
        $this->queries->reset();

        // Текстовые даты нельзя сравнивать лексикографически: offsets могут различаться.
        $segmentIds = $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT id FROM waybills_route_log
            WHERE vehicle_id = ?
              AND split_part(occurred_at, '|', 1)::timestamptz >= ?::timestamptz
              AND split_part(occurred_at, '|', 1)::timestamptz < ?::timestamptz
            SQL, [$vehicleId, '2026-10-01 00:00:00+00:00', '2026-10-02 00:00:00+00:00']);
        $trips = $this->em->createQuery('SELECT trip, predecessor FROM '.RouteLog::class.' trip LEFT JOIN trip.previous predecessor WHERE trip.id IN (:ids)')
            ->setParameter('ids', $segmentIds)
            ->getResult();

        self::assertCount(3, $trips);
        $byId = [];
        foreach ($trips as $trip) {
            $byId[(string) $trip->id] = $trip;
        }
        $cursor = $byId[$ids[4]];
        $ordered = [];
        while (isset($byId[(string) $cursor->id])) {
            array_unshift($ordered, (string) $cursor->id);
            $cursor = $cursor->previous;
            self::assertNotNull($cursor);
        }
        self::assertSame(array_slice($ids, 2, 3), $ordered);
        self::assertSame($ids[1], (string) $cursor->id);
        self::assertSame('Точка 2', $cursor->destination->location->city);
        self::assertNotNull($cursor->previous);
        self::assertSame($ids[0], (string) $cursor->previous->id);
        self::assertTrue((new \ReflectionClass(RouteLog::class))->isUninitializedLazyObject($cursor->previous));

        $sql = array_column($this->queries->getData()['default'] ?? [], 'sql');
        self::assertCount(2, $sql, implode("\n", $sql));
        self::assertLessThanOrEqual(5, count($this->em->getUnitOfWork()->getIdentityMap()[RouteLog::class] ?? []));
    }

    public function test_fetch_join_loads_a_predecessor_already_referenced_as_a_ghost(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->trip($vehicle, null, new \DateTimeImmutable('2026-10-01'), new Location('Россия', 'Москва'), 1, null, RouteLogOrigin::Manual);
        $second = $this->trip($vehicle, $first, new \DateTimeImmutable('2026-10-02'), new Location('Россия', 'Тверь'), 2, null, RouteLogOrigin::Manual);
        $this->em->persist($vehicle);
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();
        $this->em->clear();

        $reference = $this->em->getReference(RouteLog::class, $first->id);
        self::assertNotNull($reference);
        self::assertTrue((new \ReflectionClass(RouteLog::class))->isUninitializedLazyObject($reference));
        $this->queries->reset();
        $loaded = $this->em->createQuery('SELECT trip, predecessor FROM '.RouteLog::class.' trip LEFT JOIN trip.previous predecessor WHERE trip.id = :id')
            ->setParameter('id', $second->id, 'uuid')
            ->getSingleResult();

        self::assertSame($reference, $loaded->previous);
        self::assertSame('Москва', $loaded->previous->destination->location->city);
        self::assertCount(1, $this->queries->getData()['default'] ?? []);
    }

    public function test_with_previous_copy_cannot_replace_a_managed_entity(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->trip($vehicle, null, new \DateTimeImmutable('2026-10-01'), new Location('Россия', 'Москва'), 1, null, RouteLogOrigin::Manual);
        $second = $this->trip($vehicle, $first, new \DateTimeImmutable('2026-10-02'), new Location('Россия', 'Тверь'), 2, null, RouteLogOrigin::Automatic);
        $this->em->persist($vehicle);
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();
        $this->em->clear();
        $managed = $this->em->find(RouteLog::class, $second->id);
        self::assertInstanceOf(RouteLog::class, $managed);

        // Допустимая доменная копия с тем же id не является заменой managed-сущности Doctrine.
        $copy = $managed->withPrevious(null);
        self::assertNotSame($managed, $copy);
        self::assertSame($managed, $this->em->find(RouteLog::class, $copy->id));
        $this->expectException(EntityIdentityCollisionException::class);
        $this->em->persist($copy);
    }

    /** @param class-string<AbstractMigration> $migrationClass */
    private function applyTemporaryMigration(string $migrationClass): void
    {
        $migration = new $migrationClass($this->connection, new NullLogger());
        $migration->up(new Schema());
        if (in_array($migrationClass, [Version20261121000007::class, Version20261121000008::class], true)) {
            self::assertNotNull($this->connection->fetchOne("SELECT to_regclass('pg_temp.waybills_waybill')"));
            self::assertNotNull($this->connection->fetchOne("SELECT to_regclass('pg_temp.waybills_route_log')"));
            $this->connection->beginTransaction();
            try {
                foreach ($migration->getSql() as $query) {
                    $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                }
                $this->connection->commit();
            } catch (\Throwable $exception) {
                $this->connection->rollBack();
                throw $exception;
            }

            return;
        }
        foreach ($migration->getSql() as $query) {
            $sql = $query->getStatement();
            if (str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS')) {
                $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $sql);
            } else {
                // Миграция обращается только к уже созданной временной таблице.
                self::assertMatchesRegularExpression('/(?:ON|ALTER TABLE|FROM) (waybills_[a-z_]+)/', $sql);
                preg_match('/(?:ON|ALTER TABLE|FROM) (waybills_[a-z_]+)/', $sql, $matches);
                self::assertNotNull($this->connection->fetchOne('SELECT to_regclass(?)', ['pg_temp.'.$matches[1]]));
            }
            $this->connection->executeStatement($sql, $query->getParameters(), $query->getTypes());
        }
    }

    private function vehicle(): Vehicle
    {
        return new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('a123bc77'), new \DateTimeImmutable('2026-01-01'), new Location('Россия', 'База'), 100, BigDecimal::of('20'));
    }

    private function trip(Vehicle $vehicle, ?RouteLog $previous, \DateTimeImmutable $date, Location $location, int $distance, ?string $enterprise, RouteLogOrigin $origin): RouteLog
    {
        return new RouteLog(Uuid::v7(), $vehicle, $previous, $date, new Destination($location, $distance, $enterprise), $origin);
    }
}
