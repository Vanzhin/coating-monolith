<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Repository;

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
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Infrastructure\Repository\CoatingSystemRepository;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CoatingSystemRepositoryTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private EntityManagerInterface $em;
    private CoatingSystemRepository $repo;

    private ?Uuid $systemId = null;
    private ?Uuid $coatingId = null;
    private ?Uuid $manufacturerId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = new CoatingSystemRepository($this->em);
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
                }
            }
            if (null !== $this->coatingId) {
                $c = $em->find(Coating::class, $this->coatingId);
                if (null !== $c) {
                    $em->remove($c);
                }
            }
            if (null !== $this->manufacturerId) {
                $m = $em->find(Manufacturer::class, $this->manufacturerId);
                if (null !== $m) {
                    $em->remove($m);
                }
            }
            $em->flush();
            $this->cleanUpTreatment($em);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_save_and_find_by_id_round_trip(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'Производитель-ксист-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Грунтовка-тест-'.$suffix,
            'Тестовое покрытие для CoatingSystem functional test.',
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

        // Build CoatingSystem with one layer.
        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'Система-тест-'.$suffix,
            'Тестовая антикоррозионная система.',
            Substrate::STEEL_CARBON,
            $treatment,
        );
        $system->appendLayer($coating, 80);

        $this->repo->save($system);

        // Clear identity map → force real DB read.
        $this->em->clear();

        $loaded = $this->repo->findById($this->systemId);

        self::assertNotNull($loaded, 'CoatingSystem должна быть загружена по id.');
        self::assertSame('Система-тест-'.$suffix, $loaded->getTitle());
        self::assertSame('Тестовая антикоррозионная система.', $loaded->getDescription());
        self::assertSame(Substrate::STEEL_CARBON, $loaded->getSubstrate());

        $loadedTreatment = $loaded->getSurfaceTreatment();
        self::assertSame($treatment->getId(), $loadedTreatment->getId());
        self::assertSame($treatment->getCode(), $loadedTreatment->getCode());
        self::assertSame($treatment->getStandardCode(), $loadedTreatment->getStandardCode());

        self::assertSame(1, $loaded->layerCount());
        $layers = $loaded->getLayers()->toArray();
        self::assertCount(1, $layers);
        $layer = $layers[0];
        self::assertSame(1, $layer->getPosition());
        self::assertSame(80, $layer->getDft());
        self::assertSame((string) $this->coatingId, $layer->getCoating()->getId());
    }

    public function test_list_and_count_with_filter(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'МфрСписок-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'ЛакСписок-'.$suffix,
            'Покрытие для теста list/count.',
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
            'СистемаСписок-'.$suffix,
            'Описание.',
            Substrate::CONCRETE,
            $treatment,
        );
        $system->appendLayer($coating, 80);
        $this->repo->save($system);

        $this->em->clear();

        $filter = new CoatingSystemsFilter(substrates: [Substrate::CONCRETE]);
        $count = $this->repo->count($filter);
        self::assertGreaterThanOrEqual(1, $count);

        $list = $this->repo->list($filter, 10, 0);
        $ids = array_map(static fn ($s) => $s->getId(), $list);
        self::assertContains((string) $this->systemId, $ids);
    }

    public function test_remove_deletes_system_and_layers(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatmentId = Uuid::v7();
        $treatment = new SurfaceTreatment(
            $treatmentId,
            'Тестовая подготовка-удаление '.$suffix,
            'Sa 2½',
            'ISO 8501-1',
            Substrate::cases(),
        );
        $this->em->persist($treatment);

        $manufacturer = new Manufacturer(
            'МфрУдаление-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'ЛакУдаление-'.$suffix,
            'Покрытие для теста удаления.',
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

        $systemId = Uuid::v7();
        $system = new CoatingSystem(
            $systemId,
            'СистемаУдаление-'.$suffix,
            'Описание.',
            Substrate::STEEL_GALVANIZED,
            $treatment,
        );
        $system->appendLayer($coating, 80);
        $this->repo->save($system);

        $this->em->clear();

        $loaded = $this->repo->findById($systemId);
        self::assertNotNull($loaded);

        $this->repo->remove($loaded);
        $this->em->clear();

        self::assertNull($this->repo->findById($systemId));

        // Layer count should be 0 (CASCADE DELETE).
        $layerCount = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM coating_system_layer WHERE system_id = ?',
            [(string) $systemId],
        );
        self::assertSame('0', (string) $layerCount);

        // Cleanup manually since tearDown's systemId is null here.
        $c = $this->em->find(Coating::class, $coatingId);
        if (null !== $c) {
            $this->em->remove($c);
        }
        $m = $this->em->find(Manufacturer::class, Uuid::fromString($manufacturer->getId()));
        if (null !== $m) {
            $this->em->remove($m);
        }
        $this->em->flush();
        $t = $this->em->find(SurfaceTreatment::class, $treatmentId);
        if (null !== $t) {
            $this->em->remove($t);
            $this->em->flush();
        }
    }
}
