<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Cache;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CoatingSystemSearchCacheRepositoryTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private EntityManagerInterface $em;
    private Connection $conn;
    private CoatingSystemSearchCacheRepository $repo;

    private ?Uuid $systemId = null;
    private ?Uuid $coatingId = null;
    private ?Uuid $manufacturerId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $this->repo = new CoatingSystemSearchCacheRepository($this->conn);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            if (null !== $this->systemId) {
                $s = $em->find(CoatingSystem::class, $this->systemId);
                if (null !== $s) {
                    $em->remove($s);
                    $em->flush();
                }
            }
            if (null !== $this->coatingId) {
                $c = $em->find(Coating::class, $this->coatingId);
                if (null !== $c) {
                    $em->remove($c);
                    $em->flush();
                }
            }
            if (null !== $this->manufacturerId) {
                $m = $em->find(Manufacturer::class, $this->manufacturerId);
                if (null !== $m) {
                    $em->remove($m);
                    $em->flush();
                }
            }
            $this->cleanUpTreatment($em);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_upsert_inserts_new_row(): void
    {
        $system = $this->buildSystemWithLayerAndPersist();
        $this->repo->upsert($system);

        $row = $this->fetchRow($system->getId());
        self::assertIsArray($row);
        self::assertSame(0, (int) $row['min_application_time_at_20_minutes']);
        self::assertSame(5, (int) $row['max_layer_application_min_temp']);
        self::assertNotEmpty($row['search_tsvector']);
    }

    public function test_upsert_updates_existing_row(): void
    {
        $system = $this->buildSystemWithLayerAndPersist();
        $this->repo->upsert($system);

        $system->setTitle('Изменено');
        $this->repo->upsert($system);

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM coating_system_search WHERE system_id = ?',
            [$system->getId()],
        );
        self::assertSame(1, $count);
    }

    public function test_row_cascade_deleted_with_system(): void
    {
        $system = $this->buildSystemWithLayerAndPersist();
        $this->repo->upsert($system);

        $systemId = $system->getId();

        $this->em->remove($system);
        $this->em->flush();

        // System was already deleted; prevent tearDown double-remove.
        $this->systemId = null;

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM coating_system_search WHERE system_id = ?',
            [$systemId],
        );
        self::assertSame(0, $count);
    }

    // -----------------------------------------------------------------------

    private function buildSystemWithLayerAndPersist(): CoatingSystem
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'МфрКэшТест-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'ЛакКэшТест-'.$suffix,
            'Тестовое покрытие.',
            50,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(60, 200), 100, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 1440)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 240))),
            null,
            1.0,
            null,
            $manufacturer,
            $container->get(CoatingSpecification::class),
        );
        $this->em->persist($coating);
        $this->em->flush();
        $this->coatingId = $coatingId;

        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'СистемаКэшТест-'.$suffix,
            'Описание для поиска.',
            Substrate::STEEL_GALVANIZED,
            $treatment,
        );
        $system->appendLayer($coating, 80);

        $this->em->persist($system);
        $this->em->flush();

        return $system;
    }

    /** @return array<string, mixed>|null */
    private function fetchRow(string $systemId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT system_id, min_application_time_at_20_minutes, max_layer_application_min_temp, search_tsvector::text AS search_tsvector FROM coating_system_search WHERE system_id = ?',
            [$systemId],
        );

        return false === $row ? null : $row;
    }
}
