<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Infrastructure\Repository;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
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
use App\Shared\Infrastructure\Database\Migrations\Version20261122000000;
use App\Shared\Infrastructure\Database\Migrations\Version20261122000001;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Vehicle\FuelOperation;
use App\Waybills\Domain\Aggregate\Vehicle\FuelOperationType;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\RefuelingOperations;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refueling;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use App\Waybills\Infrastructure\Repository\WaybillRepository;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManager $em;
    private WaybillRepository $repository;
    private string $schema;
    private bool $ownsSchema = false;
    private int $registrationSequence = 0;
    /** @var array<string, mixed> */
    private array $params;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->params = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->getParams();
        self::assertSame('test_db', $this->params['host'] ?? null);
        self::assertSame('app_test', $this->params['dbname'] ?? null);
        $this->connection = DriverManager::getConnection($this->params);
        self::assertSame('app_test', $this->connection->fetchOne('SELECT current_database()'));
        $this->schema = 'wbtest_'.str_replace('-', '', (string) Uuid::v4());
        $this->connection->executeStatement('CREATE SCHEMA '.$this->schema);
        $this->ownsSchema = true;
        $this->connection->executeStatement('SET search_path TO '.$this->schema);
        foreach ([Version20261121000000::class, Version20261121000001::class, Version20261121000002::class, Version20261121000003::class, Version20261121000004::class, Version20261121000005::class, Version20261121000006::class, Version20261121000007::class, Version20261121000008::class, Version20261121000009::class, Version20261121000010::class, Version20261122000000::class, Version20261122000001::class] as $class) {
            // Run each migration twice to verify its idempotence.
            for ($i = 0; $i < 2; ++$i) {
                $migration = new $class($this->connection, new NullLogger());
                $migration->up(new Schema());
                $this->connection->transactional(function () use ($migration): void {
                    foreach ($migration->getSql() as $query) {
                        $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                    }
                });
            }
        }
        $config = static::getContainer()->get(EntityManagerInterface::class)->getConfiguration();
        $this->em = new EntityManager($this->connection, $config);
        $this->repository = new WaybillRepository($this->em, $this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->close();
        }
        if (isset($this->connection)) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if ($this->ownsSchema) {
                $this->connection->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');
            }
            $this->connection->close();
        }
        parent::tearDown();
    }

    public function test_round_trip_snapshots_lock_and_domain_validation(): void
    {
        $vehicle = $this->vehicle('AA123');
        $sheet = $this->sheet($vehicle, '2026-10-01', '2026-10-05', true);
        $this->repository->add($sheet);
        $this->repository->finalize($sheet);
        $loaded = $this->repository->findOneById($sheet->getId());
        self::assertNotNull($loaded);
        self::assertEquals($sheet, $loaded);
        self::assertTrue($loaded->isLocked());
        self::assertSame('Иванов Иван', $loaded->driver()->fullName);
        self::assertSame($sheet->vehicleFuelTypes(), $loaded->vehicleFuelTypes());
        self::assertSame(12.5, $loaded->fuel()->refuelings()->total());
        self::assertCount(2, iterator_to_array($loaded->route()->logs(), false));
        self::assertCount(1, $loaded->absences());
        self::assertNull($this->repository->findOneById((string) Uuid::v7()));
        $this->expectException(AppException::class);
        $this->repository->add($loaded);
    }

    public function test_existing_trip_ids_are_checked_globally_including_approved_trips(): void
    {
        $draft = $this->sheet($this->vehicle('DRAFT'), '2026-10-01', '2026-10-05', true);
        $approved = $this->sheet($this->vehicle('APPROVED'), '2026-10-01', '2026-10-05', true);
        $this->repository->add($draft);
        $this->repository->add($approved);
        $this->repository->finalize($approved);
        $draftId = (string) $draft->trips()[0]->id;
        $approvedId = (string) $approved->trips()[0]->id;
        $freeId = (string) Uuid::v7();

        self::assertFalse($this->repository->hasExistingTripIds(new StringCollection()));
        self::assertFalse($this->repository->hasExistingTripIds(new StringCollection($freeId, (string) Uuid::v7())));
        self::assertTrue($this->repository->hasExistingTripIds(new StringCollection($freeId, $draftId)));
        self::assertTrue($this->repository->hasExistingTripIds(new StringCollection($approvedId)));
        self::assertTrue($this->repository->hasExistingTripIds(new StringCollection($draftId, $approvedId)));
    }

    #[DataProvider('foreignTripStates')]
    public function test_duplicate_trip_id_rolls_back_the_draft_and_preserves_foreign_history(bool $approved): void
    {
        $foreign = $this->sheet($this->vehicle('FOREIGN'), '2026-10-01', '2026-10-05', true);
        $draft = $this->sheet($this->vehicle('OWN'), '2026-10-01', '2026-10-05', true);
        $this->repository->add($foreign);
        if ($approved) {
            $this->repository->finalize($foreign);
        }
        $this->repository->add($draft);
        $beforeTrips = $this->connection->fetchAllAssociative('SELECT * FROM waybills_route_log ORDER BY id');
        $beforeSheets = $this->connection->fetchAllAssociative('SELECT * FROM waybills_waybill ORDER BY id');

        $own = $draft->trips()[0];
        $updated = new RouteLog($own->id, $draft->vehicle(), null, $own->date, new Destination(new Location('Изменённая точка'), 19), RouteLogOrigin::Manual);
        $new = new RouteLog(Uuid::v7(), $draft->vehicle(), $updated, $own->date, new Destination(new Location('Новая точка'), 3), RouteLogOrigin::Manual);
        $foreignId = $foreign->trips()[0]->id;
        $collision = new RouteLog($foreignId, $draft->vehicle(), $new, $own->date, new Destination(new Location('Подменённая точка'), 7), RouteLogOrigin::Manual);
        $draft->setTrips($updated, $new, $collision);

        try {
            $this->repository->add($draft);
            self::fail('Чужой UUID поездки должен быть отклонён.');
        } catch (AppException $exception) {
            self::assertSame('Поездка не принадлежит этому черновику.', $exception->getMessage());
        }

        self::assertSame($beforeTrips, $this->connection->fetchAllAssociative('SELECT * FROM waybills_route_log ORDER BY id'));
        self::assertSame($beforeSheets, $this->connection->fetchAllAssociative('SELECT * FROM waybills_waybill ORDER BY id'));
    }

    /** @return iterable<string, array{bool}> */
    public static function foreignTripStates(): iterable
    {
        yield 'draft of another vehicle' => [false];
        yield 'approved history of another vehicle' => [true];
    }

    public function test_new_draft_cannot_reuse_an_approved_trip_id_of_the_same_vehicle(): void
    {
        $vehicle = $this->vehicle('APPROVED-SAME-VEHICLE');
        $approved = $this->sheet($vehicle, '2026-10-01', '2026-10-05', true);
        $this->repository->add($approved);
        $this->repository->finalize($approved);
        $beforeTrips = $this->connection->fetchAllAssociative('SELECT * FROM waybills_route_log ORDER BY id');
        $beforeSheets = $this->connection->fetchAllAssociative('SELECT * FROM waybills_waybill ORDER BY id');
        [$first, $predecessor] = $approved->trips();
        $draft = Waybill::createDraft(Uuid::v7(), 2, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-10-06'), new \DateTimeImmutable('2026-10-10')));
        $collision = new RouteLog($first->id, $vehicle, $predecessor, new \DateTimeImmutable('2026-10-06'), new Destination(new Location('Подменённая точка'), 7), RouteLogOrigin::Manual);
        $draft->setTrips($collision);

        try {
            $this->repository->add($draft);
            self::fail('Утверждённый UUID поездки нельзя использовать для нового черновика.');
        } catch (AppException $exception) {
            self::assertSame('Поездка не принадлежит этому черновику.', $exception->getMessage());
        }

        self::assertSame($beforeTrips, $this->connection->fetchAllAssociative('SELECT * FROM waybills_route_log ORDER BY id'));
        self::assertSame($beforeSheets, $this->connection->fetchAllAssociative('SELECT * FROM waybills_waybill ORDER BY id'));
    }

    public function test_repeated_save_updates_current_trip_ids_and_inserts_new_ones(): void
    {
        $draft = $this->sheet($this->vehicle('REPEATED'), '2026-10-01', '2026-10-05', true);
        $this->repository->add($draft);
        [$first, $second] = $draft->trips();
        $updatedFirst = new RouteLog($first->id, $draft->vehicle(), null, $first->date, new Destination($first->destination->location, 19), $first->origin);
        $updatedSecond = new RouteLog($second->id, $draft->vehicle(), $updatedFirst, $second->date, $second->destination, $second->origin);
        $new = new RouteLog(Uuid::v7(), $draft->vehicle(), $updatedSecond, $second->date, new Destination(new Location('Новая точка'), 7), RouteLogOrigin::Manual);
        $draft->setTrips($updatedFirst, $updatedSecond, $new);

        $this->repository->add($draft);
        $this->repository->add($draft);
        $this->em->clear();
        $loaded = $this->repository->findOneById($draft->getId());

        self::assertInstanceOf(Waybill::class, $loaded);
        self::assertSame([(string) $first->id, (string) $second->id, (string) $new->id], array_map(static fn (RouteLog $trip): string => (string) $trip->id, $loaded->trips()));
        self::assertSame([19, 15, 7], array_map(static fn (RouteLog $trip): int => $trip->distanceInKilometers(), $loaded->trips()));
        self::assertSame(141, $loaded->odometerEnd());
        self::assertSame(3, $this->repository->versionOf($loaded));
    }

    public function test_new_trip_does_not_hide_other_database_constraint_violations(): void
    {
        $draft = $this->sheet($this->vehicle('CHECK'), '2026-10-01', '2026-10-05');
        $this->repository->add($draft);
        $this->connection->executeStatement('ALTER TABLE waybills_route_log ADD CONSTRAINT test_trip_distance CHECK (distance < 5)');
        $this->addTrip($draft, 10);

        try {
            $this->repository->add($draft);
            self::fail('Другое ограничение базы должно вернуть исходную ошибку.');
        } catch (DriverException $exception) {
            self::assertSame('23514', $exception->getSQLState());
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
    }

    public function test_new_trip_does_not_hide_another_unique_constraint_violation(): void
    {
        $foreign = $this->sheet($this->vehicle('UNIQUE-FOREIGN'), '2026-10-01', '2026-10-05');
        $draft = $this->sheet($this->vehicle('UNIQUE-OWN'), '2026-10-01', '2026-10-05');
        $this->addTrip($foreign, 3);
        $this->repository->add($foreign);
        $this->repository->add($draft);
        $this->connection->executeStatement('ALTER TABLE waybills_route_log ADD CONSTRAINT test_unique_distance UNIQUE (distance)');
        $this->addTrip($draft, 3);

        try {
            $this->repository->add($draft);
            self::fail('Другое ограничение уникальности должно вернуть исходную ошибку.');
        } catch (UniqueConstraintViolationException $exception) {
            self::assertSame('23505', $exception->getSQLState());
            self::assertStringContainsString('test_unique_distance', $exception->getMessage());
        }

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
    }

    public function test_concurrent_insertion_of_a_free_trip_id_is_rejected_after_the_other_transaction_commits(): void
    {
        if (!function_exists('proc_open') || !extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('Для проверки гонки требуется отдельный процесс с PDO PostgreSQL.');
        }

        $draft = $this->sheet($this->vehicle('RACE-OWN'), '2026-10-01', '2026-10-05');
        $foreign = $this->sheet($this->vehicle('RACE-FOREIGN'), '2026-10-01', '2026-10-05');
        $this->addTrip($draft, 1);
        $this->repository->add($draft);
        $this->repository->add($foreign);
        $own = $draft->trips()[0];
        $beforeTrip = $this->connection->fetchAssociative('SELECT * FROM waybills_route_log WHERE id = ?', [(string) $own->id]);
        $beforeSheet = $this->connection->fetchAssociative('SELECT * FROM waybills_waybill WHERE id = ?', [$draft->getId()]);
        $newId = Uuid::v7();
        $updated = new RouteLog($own->id, $draft->vehicle(), null, $own->date, new Destination(new Location('Изменённая точка'), 5), RouteLogOrigin::Manual);
        $collision = new RouteLog($newId, $draft->vehicle(), $updated, $own->date, new Destination(new Location('Другая точка'), 7), RouteLogOrigin::Manual);
        $draft->setTrips($updated, $collision);
        $this->connection->executeStatement("SET lock_timeout = '15s'");

        // Другая сессия занимает UUID, но фиксирует его только после того, как сохранение листа действительно заблокировано.
        $script = <<<'PHP'
            $config = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
            $pdo = new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['dbname']),
                $config['user'],
                $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $pdo->prepare("SELECT set_config('search_path', ?, false)")->execute([$config['schema']]);
            $childPid = (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn();
            $pdo->beginTransaction();
            $insert = $pdo->prepare(<<<'SQL'
                INSERT INTO waybills_route_log (id, vehicle_id, draft_waybill_id, previous_id, occurred_at, location, enterprise, distance, origin)
                SELECT ?, ?, ?, NULL, occurred_at, location, enterprise, distance, origin FROM waybills_route_log WHERE id = ?
                SQL);
            $insert->execute([$config['id'], $config['vehicle'], $config['draft'], $config['source']]);
            if (1 !== $insert->rowCount()) {
                throw new RuntimeException('Исходная поездка не найдена.');
            }
            fwrite(STDOUT, "ready\n");
            fflush(STDOUT);
            $blocked = $pdo->prepare('SELECT CASE WHEN CAST(? AS integer) = ANY(pg_blocking_pids(CAST(? AS integer))) THEN 1 ELSE 0 END');
            $deadline = microtime(true) + 10;
            do {
                $blocked->execute([$childPid, $config['parent_pid']]);
                if (1 === (int) $blocked->fetchColumn()) {
                    fwrite(STDOUT, "blocked\n");
                    $pdo->commit();
                    fwrite(STDOUT, "committed\n");
                    exit(0);
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Сохранение не дождалось конкурирующей транзакции.');
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            fwrite($pipes[0], json_encode([
                'host' => $this->params['host'],
                'port' => $this->params['port'] ?? 5432,
                'dbname' => $this->params['dbname'],
                'user' => $this->params['user'],
                'password' => $this->params['password'] ?? '',
                'schema' => $this->schema,
                'id' => (string) $newId,
                'vehicle' => $foreign->vehicleId(),
                'draft' => $foreign->getId(),
                'source' => (string) $own->id,
                'parent_pid' => (int) $this->connection->fetchOne('SELECT pg_backend_pid()'),
            ], JSON_THROW_ON_ERROR)."\n");
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 15);
            self::assertSame("ready\n", fgets($pipes[1]));
            self::assertFalse($this->repository->hasExistingTripIds(new StringCollection((string) $newId)));

            try {
                $this->repository->add($draft);
                self::fail('Конкурирующее сохранение должно отклонить занятый UUID.');
            } catch (AppException $exception) {
                self::assertSame('Поездка не принадлежит этому черновику.', $exception->getMessage());
            }

            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $process = null;
            self::assertSame(0, $exitCode, $error);
            self::assertSame("blocked\ncommitted\n", $output);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
        }

        self::assertSame($beforeTrip, $this->connection->fetchAssociative('SELECT * FROM waybills_route_log WHERE id = ?', [(string) $own->id]));
        self::assertSame($beforeSheet, $this->connection->fetchAssociative('SELECT * FROM waybills_waybill WHERE id = ?', [$draft->getId()]));
        $foreignTrip = $this->connection->fetchAssociative('SELECT vehicle_id, draft_waybill_id, previous_id, distance FROM waybills_route_log WHERE id = ?', [(string) $newId]);
        self::assertIsArray($foreignTrip);
        self::assertSame($foreign->vehicleId(), $foreignTrip['vehicle_id']);
        self::assertSame($foreign->getId(), $foreignTrip['draft_waybill_id']);
        self::assertNull($foreignTrip['previous_id']);
        self::assertSame(1, (int) $foreignTrip['distance']);
    }

    public function test_concurrent_reverse_trip_ids_report_deadlock_and_roll_back_the_losing_draft(): void
    {
        if (!function_exists('proc_open') || !extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('Для проверки гонки требуются отдельные процессы с PDO PostgreSQL.');
        }

        $drafts = [
            $this->sheet($this->vehicle('REVERSE-FIRST'), '2026-10-01', '2026-10-05'),
            $this->sheet($this->vehicle('REVERSE-SECOND'), '2026-10-01', '2026-10-05'),
        ];
        foreach ($drafts as $draft) {
            $this->repository->add($draft);
        }
        $before = array_map(fn (Waybill $draft) => $this->connection->fetchAssociative('SELECT * FROM waybills_waybill WHERE id = ?', [$draft->getId()]), $drafts);
        $ids = [(string) Uuid::v7(), (string) Uuid::v7()];
        self::assertFalse($this->repository->hasExistingTripIds(new StringCollection(...$ids)));

        // После первой вставки обе транзакции ждут одного барьера, удерживая разные UUID.
        $barrier = random_int(1, PHP_INT_MAX);
        $this->connection->executeStatement(<<<SQL
            CREATE FUNCTION wait_for_reverse_trip_race() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                PERFORM pg_advisory_xact_lock_shared($barrier);
                RETURN NEW;
            END
            \$\$
            SQL);
        $this->connection->executeStatement('CREATE TRIGGER wait_for_reverse_trip_race AFTER INSERT ON waybills_route_log FOR EACH ROW EXECUTE FUNCTION wait_for_reverse_trip_race()');
        $this->connection->executeStatement("SET lock_timeout = '15s'");

        $script = <<<'PHP'
            require 'vendor/autoload.php';
            $config = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
            $kernel = new App\Shared\Infrastructure\Kernel('test', true);
            $kernel->boot();
            $ormConfig = $kernel->getContainer()->get('doctrine')->getManager()->getConfiguration();
            $connection = Doctrine\DBAL\DriverManager::getConnection($config['connection']);
            $connection->executeStatement('SET search_path TO '.$config['schema']);
            $connection->executeStatement("SELECT set_config('application_name', ?, false)", [$config['application']]);
            $connection->executeStatement("SET lock_timeout = '15s'");
            $connection->executeStatement("SET statement_timeout = '20s'");
            $manager = new Doctrine\ORM\EntityManager($connection, $ormConfig);
            $repository = new App\Waybills\Infrastructure\Repository\WaybillRepository($manager, $connection);
            $draft = $repository->findOneById($config['draft']);
            if (!$draft instanceof App\Waybills\Domain\Aggregate\Waybill\Waybill) {
                throw new RuntimeException('Черновик не найден.');
            }
            $first = new App\Waybills\Domain\Aggregate\Route\RouteLog(
                Symfony\Component\Uid\Uuid::fromString($config['ids'][0]), $draft->vehicle(), null, $draft->period()->from(),
                new App\Waybills\Domain\Aggregate\Route\Destination(new App\Waybills\Domain\Aggregate\Route\Location('Первая точка'), 3),
                App\Waybills\Domain\Aggregate\Route\RouteLogOrigin::Manual,
            );
            $second = new App\Waybills\Domain\Aggregate\Route\RouteLog(
                Symfony\Component\Uid\Uuid::fromString($config['ids'][1]), $draft->vehicle(), $first, $draft->period()->from(),
                new App\Waybills\Domain\Aggregate\Route\Destination(new App\Waybills\Domain\Aggregate\Route\Location('Вторая точка'), 7),
                App\Waybills\Domain\Aggregate\Route\RouteLogOrigin::Manual,
            );
            $draft->setTrips($first, $second);
            fwrite(STDOUT, "\n".$config['protocol'].'PID:'.$connection->fetchOne('SELECT pg_backend_pid()')."\n");
            fflush(STDOUT);
            if ("go\n" !== fgets(STDIN)) {
                throw new RuntimeException('Сохранение не запущено.');
            }
            try {
                $repository->add($draft);
                $result = ['status' => 'saved'];
            } catch (Throwable $exception) {
                $causes = [];
                for ($cause = $exception->getPrevious(); null !== $cause; $cause = $cause->getPrevious()) {
                    $causes[] = ['class' => $cause::class, 'state' => method_exists($cause, 'getSQLState') ? $cause->getSQLState() : $cause->getCode(), 'message' => $cause->getMessage()];
                }
                $result = [
                    'status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage(),
                    'state' => method_exists($exception, 'getSQLState') ? $exception->getSQLState() : $exception->getCode(), 'causes' => $causes,
                ];
            }
            fwrite(STDOUT, "\n".$config['protocol'].'RESULT:'.json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
            fflush(STDOUT);
            PHP;

        $processes = [];
        $protocol = 'waybill_race_'.str_replace('-', '', (string) Uuid::v4()).'_';
        $describeOutput = static function (array $workers): string {
            $output = [];
            foreach ($workers as $index => $worker) {
                $output[] = 'Процесс '.$index.":\nstdout:\n".$worker['output']."\nstderr:\n".$worker['errors'];
            }

            return implode("\n", $output);
        };
        $readMessages = static function (array &$workers, string $message) use ($protocol, $describeOutput): array {
            $prefix = $protocol.$message.':';
            $deadline = microtime(true) + 20;
            $messages = [];
            while (true) {
                foreach (array_keys($workers) as $index) {
                    while (false !== $end = strpos($workers[$index]['pending'], "\n")) {
                        $line = substr($workers[$index]['pending'], 0, $end);
                        $workers[$index]['pending'] = substr($workers[$index]['pending'], $end + 1);
                        if (str_starts_with($line, $prefix)) {
                            if (array_key_exists($index, $messages)) {
                                self::fail('Повторный маркер '.$message.".\n".$describeOutput($workers));
                            }
                            $messages[$index] = substr($line, strlen($prefix));
                        } elseif (str_starts_with($line, $protocol)) {
                            self::fail('Неожиданный маркер при ожидании '.$message.".\n".$describeOutput($workers));
                        }
                    }
                }
                if (count($messages) === count($workers)) {
                    ksort($messages);

                    return array_values($messages);
                }

                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    self::fail('Таймаут ожидания маркера '.$message.".\n".$describeOutput($workers));
                }
                $read = [];
                foreach ($workers as $index => $worker) {
                    if (feof($worker['pipes'][1])) {
                        if (!array_key_exists($index, $messages)) {
                            $workers[$index]['errors'] .= stream_get_contents($worker['pipes'][2]);
                            self::fail('Процесс завершился без маркера '.$message.".\n".$describeOutput($workers));
                        }
                    } else {
                        $read[] = $worker['pipes'][1];
                    }
                    if (!feof($worker['pipes'][2])) {
                        $read[] = $worker['pipes'][2];
                    }
                }
                $write = null;
                $except = null;
                $seconds = (int) $remaining;
                $ready = stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1000000));
                if (false === $ready) {
                    self::fail('Не удалось прочитать маркер '.$message.".\n".$describeOutput($workers));
                }
                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 8192);
                    if (false === $chunk) {
                        self::fail('Ошибка чтения маркера '.$message.".\n".$describeOutput($workers));
                    }
                    foreach ($workers as $index => $worker) {
                        if ($pipe === $worker['pipes'][1]) {
                            $workers[$index]['output'] .= $chunk;
                            $workers[$index]['pending'] .= $chunk;
                            break;
                        }
                        if ($pipe === $worker['pipes'][2]) {
                            $workers[$index]['errors'] .= $chunk;
                            break;
                        }
                    }
                }
            }
        };
        $barrierHeld = false;
        try {
            foreach ($drafts as $index => $draft) {
                $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $processes[] = ['process' => $process, 'pipes' => $pipes, 'output' => '', 'errors' => '', 'pending' => ''];
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                fwrite($pipes[0], json_encode([
                    'connection' => [
                        'driver' => 'pdo_pgsql', 'host' => $this->params['host'], 'port' => $this->params['port'] ?? 5432,
                        'dbname' => $this->params['dbname'], 'user' => $this->params['user'], 'password' => $this->params['password'] ?? '',
                    ],
                    'schema' => $this->schema, 'draft' => $draft->getId(), 'ids' => 0 === $index ? $ids : array_reverse($ids),
                    'protocol' => $protocol, 'application' => $protocol.$index,
                ], JSON_THROW_ON_ERROR)."\n");
            }
            $pids = [];
            foreach ($readMessages($processes, 'PID') as $pid) {
                self::assertMatchesRegularExpression('/^[1-9]\d*$/D', $pid, $describeOutput($processes));
                $pids[] = (int) $pid;
            }
            self::assertNotSame($pids[0], $pids[1]);
            $sessions = $this->connection->fetchAllKeyValue(
                'SELECT application_name, pid FROM pg_stat_activity WHERE datname = current_database() AND application_name IN (?, ?)',
                [$protocol.'0', $protocol.'1'],
            );
            foreach ($pids as $index => $pid) {
                self::assertSame($pid, (int) ($sessions[$protocol.$index] ?? 0), $describeOutput($processes));
            }
            $this->connection->executeStatement('SELECT pg_advisory_lock(?::bigint)', [$barrier]);
            $barrierHeld = true;
            foreach ($processes as $worker) {
                fwrite($worker['pipes'][0], "go\n");
                fclose($worker['pipes'][0]);
            }

            $coordinator = (int) $this->connection->fetchOne('SELECT pg_backend_pid()');
            $deadline = microtime(true) + 10;
            do {
                $blocked = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM pg_stat_activity WHERE pid IN (?::integer, ?::integer) AND ?::integer = ANY(pg_blocking_pids(pid))',
                    [$pids[0], $pids[1], $coordinator],
                );
                if (2 === $blocked) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $blocked, "Обе транзакции должны вставить первые разные UUID до снятия барьера.\n".$describeOutput($processes));
            $this->connection->executeStatement('SELECT pg_advisory_unlock(?::bigint)', [$barrier]);
            $barrierHeld = false;

            $results = [];
            foreach ($readMessages($processes, 'RESULT') as $payload) {
                try {
                    $result = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    self::fail('Некорректный результат процесса: '.$exception->getMessage()."\n".$describeOutput($processes));
                }
                self::assertIsArray($result, $describeOutput($processes));
                self::assertContains($result['status'] ?? null, ['saved', 'error'], $describeOutput($processes));
                if ('error' === $result['status']) {
                    self::assertIsString($result['class'] ?? null, $describeOutput($processes));
                    self::assertIsString($result['message'] ?? null, $describeOutput($processes));
                    self::assertIsArray($result['causes'] ?? null, $describeOutput($processes));
                    foreach ($result['causes'] as $cause) {
                        self::assertIsArray($cause, $describeOutput($processes));
                    }
                }
                $results[] = $result;
            }
            foreach ($processes as $index => $worker) {
                $deadline = microtime(true) + 5;
                do {
                    foreach ($processes as $otherIndex => $other) {
                        if (is_resource($other['pipes'][1])) {
                            $processes[$otherIndex]['output'] .= stream_get_contents($other['pipes'][1]);
                        }
                        if (is_resource($other['pipes'][2])) {
                            $processes[$otherIndex]['errors'] .= stream_get_contents($other['pipes'][2]);
                        }
                    }
                    $status = proc_get_status($worker['process']);
                    if (!$status['running']) {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                self::assertFalse($status['running'], "Процесс не завершился после сохранения.\n".$describeOutput($processes));
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                proc_close($worker['process']);
                $processes[$index]['process'] = null;
                self::assertSame(0, $status['exitcode'], $describeOutput($processes));
            }
        } finally {
            try {
                if ($barrierHeld) {
                    $this->connection->executeStatement('SELECT pg_advisory_unlock(?::bigint)', [$barrier]);
                }
            } finally {
                foreach ($processes as $worker) {
                    if (is_resource($worker['process'])) {
                        proc_terminate($worker['process'], 9);
                        foreach ($worker['pipes'] as $pipe) {
                            if (is_resource($pipe)) {
                                fclose($pipe);
                            }
                        }
                        proc_close($worker['process']);
                    }
                }
            }
        }

        $diagnostic = json_encode($results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n".$describeOutput($processes);
        $winners = array_keys(array_filter($results, static fn (array $result): bool => 'saved' === $result['status']));
        $losers = array_keys(array_filter($results, static fn (array $result): bool => 'error' === $result['status']));
        self::assertCount(1, $winners, $diagnostic);
        self::assertCount(1, $losers, $diagnostic);
        $winner = $winners[0];
        $loser = $losers[0];
        self::assertSame($before[$loser], $this->connection->fetchAssociative('SELECT * FROM waybills_waybill WHERE id = ?', [$drafts[$loser]->getId()]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log WHERE draft_waybill_id = ?', [$drafts[$loser]->getId()]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT version FROM waybills_waybill WHERE id = ?', [$drafts[$winner]->getId()]));
        self::assertSame(110, (int) $this->connection->fetchOne('SELECT odometer_end FROM waybills_waybill WHERE id = ?', [$drafts[$winner]->getId()]));
        $winnerIds = 0 === $winner ? $ids : array_reverse($ids);
        $trips = $this->connection->fetchAllAssociative('SELECT id, vehicle_id, draft_waybill_id, previous_id, distance FROM waybills_route_log ORDER BY distance');
        self::assertCount(2, $trips);
        foreach ($winnerIds as $index => $id) {
            self::assertSame($id, $trips[$index]['id']);
            self::assertSame($drafts[$winner]->vehicleId(), $trips[$index]['vehicle_id']);
            self::assertSame($drafts[$winner]->getId(), $trips[$index]['draft_waybill_id']);
            self::assertSame(0 === $index ? null : $winnerIds[0], $trips[$index]['previous_id']);
            self::assertSame(0 === $index ? 3 : 7, (int) $trips[$index]['distance']);
        }
        $isDeadlock = static fn (array $error): bool => DeadlockException::class === ($error['class'] ?? null) && '40P01' === ($error['state'] ?? null);
        self::assertNotEmpty(array_filter([$results[$loser], ...$results[$loser]['causes']], $isDeadlock), $diagnostic);
        self::assertSame(AppException::class, $results[$loser]['class'], $diagnostic);
        self::assertSame('Не удалось сохранить путевой лист из-за одновременного изменения. Попробуйте ещё раз.', $results[$loser]['message']);
        self::assertNotEmpty(array_filter($results[$loser]['causes'], $isDeadlock), $diagnostic);
    }

    public function test_periods_are_global_to_vehicle_and_include_both_boundary_days(): void
    {
        $first = $this->vehicle('BB123');
        $second = $this->vehicle('CC123');
        $firstSheet = $this->sheet($first, '2026-10-01', '2026-10-03');
        $this->repository->add($firstSheet);
        $this->repository->finalize($firstSheet);
        $secondSheet = $this->sheet($first, '2026-10-04', '2026-10-06');
        $this->repository->add($secondSheet);
        $this->repository->finalize($secondSheet);
        $this->repository->add($this->sheet($first, '2026-10-07', '2026-10-09'));
        $this->repository->add($this->sheet($second, '2026-10-01', '2026-10-09'));
        try {
            $this->repository->add($this->sheet($first, '2026-10-09', '2026-10-10', false, 'another owner'));
            self::fail('The shared day must overlap.');
        } catch (AppException $e) {
            self::assertStringNotContainsString('owner', $e->getMessage());
            self::assertStringNotContainsString('Иванов', $e->getMessage());
        }
    }

    public function test_revision_is_checked_against_current_approved_periods(): void
    {
        $vehicle = $this->vehicle('DD123');
        $previous = $this->sheet($vehicle, '2026-10-01', '2026-10-02', true);
        $previous->lock();
        foreach ($previous->trips() as $trip) {
            $this->em->persist($trip);
        }
        $this->em->persist($previous);
        $this->em->flush();
        $sheet = $this->sheet($vehicle, '2026-10-03', '2026-10-04');
        $this->repository->add($sheet);
        $sheet->reviseDraft($this->period('2026-10-02', '2026-10-03'), 100, $sheet->fuel(), new Route($vehicle));
        $this->expectException(AppException::class);
        $this->repository->add($sheet);
    }

    public function test_another_owner_must_wait_until_the_draft_is_approved(): void
    {
        $vehicle = $this->vehicle('NN123');
        $draft = $this->sheet($vehicle, '2026-10-01', '2026-10-31', owner: 'first');
        $this->repository->add($draft);
        $firstDriver = $vehicle->driver();
        self::assertNotNull($firstDriver);
        $secondDriver = new DriverProfile(Uuid::v7(), 'second', new DriverDetails('Другой водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), new Location('База'));
        $vehicle->leave($firstDriver);
        $vehicle->enter($secondDriver);
        $this->em->persist($secondDriver);
        $this->em->flush();
        $next = $this->sheet($vehicle, '2026-11-01', '2026-11-30', owner: 'second');

        try {
            $this->repository->add($next);
            self::fail('Второй черновик автомобиля должен быть отклонён.');
        } catch (AppException $e) {
            self::assertStringContainsString('черновик', $e->getMessage());
            self::assertStringNotContainsString('first', $e->getMessage());
        }

        $this->repository->finalize($draft);
        $this->repository->add($next);
        self::assertNotNull($this->repository->findOneById($next->getId()));
    }

    public function test_database_rejects_second_draft_even_when_application_checks_are_bypassed(): void
    {
        $vehicle = $this->vehicle('PP123');
        $sheet = $this->sheet($vehicle, '2026-10-01', '2026-10-31');
        $this->repository->add($sheet);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->executeStatement(
            'INSERT INTO waybills_waybill (id, vehicle_id, number, period_from, period_to, locked, odometer_start, fuel_start) SELECT ?, vehicle_id, number, period_from, period_to, locked, odometer_start, fuel_start FROM waybills_waybill WHERE id = ?',
            [(string) Uuid::v7(), $sheet->getId()],
        );
    }

    public function test_old_nonoverlapping_period_cannot_be_created_after_approval(): void
    {
        $vehicle = $this->vehicle('QQ123');
        $approved = $this->sheet($vehicle, '2026-10-01', '2026-10-31');
        $this->repository->add($approved);
        $this->repository->finalize($approved);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('после последнего утверждённого');
        $this->repository->add($this->sheet($vehicle, '2026-09-01', '2026-09-30'));
    }

    public function test_vehicle_is_immutable_and_stale_copy_cannot_overwrite(): void
    {
        $vehicle = $this->vehicle('HH123');
        $sheet = $this->sheet($vehicle, '2026-10-01', '2026-10-02');
        $this->repository->add($sheet);
        self::assertTrue((new \ReflectionProperty(Waybill::class, 'vehicle'))->isReadOnly());
        $otherManager = new EntityManager($this->connection, $this->em->getConfiguration());
        $otherRepository = new WaybillRepository($otherManager, $this->connection);
        $stale = $otherRepository->findOneById($sheet->getId());
        self::assertInstanceOf(Waybill::class, $stale);
        $this->addTrip($sheet, 1);
        $this->repository->add($sheet);
        $this->addTrip($stale, 2);
        $this->expectException(AppException::class);
        $otherRepository->add($stale);
    }

    public function test_snapshot_isolation_is_rejected(): void
    {
        $sheet = $this->sheet($this->vehicle('JJ123'), '2026-10-01', '2026-10-02');
        $this->connection->beginTransaction();
        $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        try {
            $this->repository->add($sheet);
            self::fail('Snapshot isolation cannot guarantee fresh periods after the lock.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('READ COMMITTED', $e->getMessage());
        } finally {
            $this->connection->rollBack();
        }
    }

    public function test_stale_edit_cannot_overwrite_a_newer_edit(): void
    {
        $sheet = $this->sheet($this->vehicle('MM123'), '2026-10-01', '2026-10-02');
        $this->repository->add($sheet);
        $otherManager = new EntityManager($this->connection, $this->em->getConfiguration());
        $otherRepository = new WaybillRepository($otherManager, $this->connection);
        $stale = $otherRepository->findOneById($sheet->getId());
        self::assertInstanceOf(Waybill::class, $stale);
        $this->addTrip($sheet, 1);
        $this->repository->add($sheet);
        $this->addTrip($stale, 2);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('изменился');
        $otherRepository->add($stale);
    }

    public function test_creates_and_edits_wait_for_vehicle_locks(): void
    {
        $a = $this->vehicle('FF123');
        $b = $this->vehicle('GG123');
        $sheet = $this->sheet($a, '2026-10-01', '2026-10-02');
        $this->repository->add($sheet);
        $other = DriverManager::getConnection($this->params);
        $other->executeStatement('SET search_path TO '.$this->schema);
        $other->executeStatement("SET lock_timeout = '100ms'");
        $otherManager = new EntityManager($other, $this->em->getConfiguration());
        $repo = new WaybillRepository($otherManager, $other);
        $loaded = $repo->findOneById($sheet->getId());
        self::assertInstanceOf(Waybill::class, $loaded);
        $this->addTrip($loaded, 1);
        $otherVehicle = $otherManager->find(Vehicle::class, $b->getId());
        self::assertInstanceOf(Vehicle::class, $otherVehicle);
        $new = Waybill::createDraft(Uuid::v7(), 1, $otherVehicle, new DateTimeInterval(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-02')));
        foreach ([[$a, $loaded], [$b, $new]] as [$lockedVehicle, $candidate]) {
            $this->connection->beginTransaction();
            $this->connection->fetchOne('SELECT id FROM waybills_vehicle WHERE id = ? FOR UPDATE', [$lockedVehicle->getId()]);
            try {
                $repo->add($candidate);
                self::fail('Сохранение должно ждать блокировки автомобиля.');
            } catch (DriverException $e) {
                self::assertSame('55P03', $e->getSQLState());
            } finally {
                $this->connection->rollBack();
            }
        }
        $otherManager->close();
        $other->close();
    }

    public function test_remove_deletes_only_draft_and_its_journal_rows(): void
    {
        $vehicle = $this->vehicle('RR123');
        $sheet = $this->sheet($vehicle, '2026-10-01', '2026-10-02');
        $this->addTrip($sheet, 1);
        $refueling = new FuelOperation(Uuid::v7(), $vehicle, $sheet->period()->from(), FuelType::Ai95, BigDecimal::of('0.123456789012345678901'), FuelOperationType::Refueling);
        $sheet->reviseDraftWithRefuelingOperations($sheet->period(), $sheet->odometerEnd(), $sheet->fuel(), $sheet->route(), new RefuelingOperations($refueling));
        $this->repository->add($sheet);

        $this->repository->remove($sheet);

        self::assertNull($this->repository->findOneById($sheet->getId()));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_fuel_operation'));
        self::assertNotNull($this->em->find(Vehicle::class, $vehicle->getId()));
        $this->repository->add($this->sheet($vehicle, '2026-10-01', '2026-10-02'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
    }

    public function test_stale_copy_cannot_delete_a_newer_draft(): void
    {
        $sheet = $this->sheet($this->vehicle('SS123'), '2026-10-01', '2026-10-02');
        $this->repository->add($sheet);
        $otherManager = new EntityManager($this->connection, $this->em->getConfiguration());
        $otherRepository = new WaybillRepository($otherManager, $this->connection);
        $stale = $otherRepository->findOneById($sheet->getId());
        self::assertInstanceOf(Waybill::class, $stale);
        $this->addTrip($sheet, 1);
        $this->repository->add($sheet);

        try {
            $otherRepository->remove($stale);
            self::fail('Устаревшая копия не должна удалять изменённый черновик.');
        } catch (AppException $exception) {
            self::assertStringContainsString('изменился', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
    }

    public function test_approved_sheet_and_its_history_cannot_be_removed(): void
    {
        $sheet = $this->sheet($this->vehicle('TT123'), '2026-10-01', '2026-10-02', true);
        $this->repository->add($sheet);
        $this->repository->finalize($sheet);

        try {
            $this->repository->remove($sheet);
            self::fail('Утверждённый лист нельзя удалить.');
        } catch (AppException $exception) {
            self::assertStringContainsString('нельзя изменять или удалять', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_waybill'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waybills_route_log'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM waybills_fuel_operation WHERE type = 'reconciliation'"));
    }

    private function addTrip(Waybill $waybill, int $distance): void
    {
        $trip = new RouteLog(Uuid::v7(), $waybill->vehicle(), null, $waybill->period()->from(), new Destination(new Location('Назначение'), $distance), RouteLogOrigin::Manual);
        $waybill->setTrips($trip);
    }

    private function vehicle(string $number): Vehicle
    {
        $registration = sprintf('a%03dbc77', ++$this->registrationSequence);
        $base = new Location('База');
        $vehicle = new Vehicle(Uuid::v7(), 'КамАЗ', new RegistrationNumber($registration), new \DateTimeImmutable('2025-01-01'), $base, 100, BigDecimal::of(10), new PositiveNumber(32.5));
        $driver = new DriverProfile(Uuid::v7(), 'owner-'.$number, new DriverDetails('Иванов Иван', '1234567890', new \DateTimeImmutable('2020-01-01T12:34:56.123456+03:00'), '11223344595'), $base);
        $vehicle->enter($driver);
        $this->em->persist($driver);
        $this->em->persist($vehicle);
        $this->em->flush();

        return $vehicle;
    }

    private function sheet(Vehicle $vehicle, string $from, string $to, bool $detailed = false, string $owner = 'owner'): Waybill
    {
        $base = new Location('База');
        $destination = new Location('Клиент');
        $date = new \DateTimeImmutable($from.'T12:34:56.123456+03:00');
        $first = $detailed ? new RouteLog(Uuid::v7(), $vehicle, null, $date, new Destination($destination, 15), RouteLogOrigin::Automatic) : null;
        $last = null === $first ? null : new RouteLog(Uuid::v7(), $vehicle, $first, $date, new Destination($base, 15), RouteLogOrigin::Automatic);
        $route = new Route($vehicle, $first, $last);
        $fuel = $detailed ? new Fuel(10, 15, new Refuelings(null, new Refueling($date, new PositiveNumber(12.5))), 'АИ-95') : new Fuel(10, 10, new Refuelings(), 'АИ-95');
        $absences = $detailed ? [$this->period($to, $to)] : [];

        return Waybill::create(Uuid::v7(), 1, $vehicle, $this->period($from, $to), $detailed ? 130 : 100, $fuel, $route, ...$absences);
    }

    private function period(string $from, string $to): WaybillPeriod
    {
        return WaybillPeriod::fromDates(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }
}
