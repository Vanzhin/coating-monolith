<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Projector;

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
use App\Coatings\Domain\Aggregate\Tag\Specification\TagSpecification;
use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Coatings\Infrastructure\Repository\CoatingSystemRepository;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CoatingSystemSearchProjectorTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private EntityManagerInterface $em;
    private CoatingSystemRepository $repo;

    private ?Uuid $systemId = null;
    private ?Uuid $coatingId = null;
    private ?Uuid $manufacturerId = null;
    /** @var list<string> */
    private array $extraCoatingIds = [];
    /** @var list<string> */
    private array $extraManufacturerIds = [];
    /** @var list<string> */
    private array $tagIds = [];

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
                    $em->flush();
                }
            }
            if (null !== $this->coatingId) {
                $c = $em->find(Coating::class, $this->coatingId);
                if (null !== $c) {
                    $em->remove($c);
                }
            }
            foreach ($this->extraCoatingIds as $id) {
                $c = $em->find(Coating::class, Uuid::fromString($id));
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
            foreach ($this->extraManufacturerIds as $id) {
                $m = $em->find(Manufacturer::class, Uuid::fromString($id));
                if (null !== $m) {
                    $em->remove($m);
                }
            }
            foreach ($this->tagIds as $id) {
                $t = $em->find(Tag::class, $id);
                if (null !== $t) {
                    $em->remove($t);
                }
            }
            $em->flush();
            $this->extraCoatingIds = [];
            $this->extraManufacturerIds = [];
            $this->tagIds = [];
            $this->cleanUpTreatment($em);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    /**
     * Creates a coating with CoatingBase::EP and isZincRich=false (primerType=OTHER).
     * Uses STEEL_GALVANIZED substrate + 1 layer with dft=80.
     *
     * Expected matching rules (mnoc=1, ndft<=80, primerBinders contains EP, substrate=GALVANIZED):
     * - C2 / HIGH  (1, 80, EP-PUR)
     * - C3 / MEDIUM (1, 80, EP-PUR)
     * - C4 / LOW   (1, 80, EP-PUR)
     */
    public function test_projector_populates_compliance_rows_on_persist(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);
        [$coating] = $this->buildCoating($container, $suffix, CoatingBase::EP);

        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'ПроекторТест-'.$suffix,
            '',
            Substrate::STEEL_GALVANIZED,
            $treatment,
        );
        $system->appendLayer($coating, 80);

        $this->repo->save($system);

        $rows = $this->fetchComplianceRows($this->systemId);

        self::assertGreaterThan(0, count($rows), 'После save() должны появиться строки compliance.');
        $keys = array_map(static fn (array $r) => $r['category'].'/'.$r['durability'], $rows);
        self::assertContains('C2/HIGH', $keys);
        self::assertContains('C3/MEDIUM', $keys);
        self::assertContains('C4/LOW', $keys);
    }

    public function test_projector_recalculates_on_update(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);
        [$coating] = $this->buildCoating($container, $suffix, CoatingBase::EP);

        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'ПроекторОбновление-'.$suffix,
            '',
            Substrate::STEEL_GALVANIZED,
            $treatment,
        );
        $system->appendLayer($coating, 80);
        $this->repo->save($system);

        $countBefore = count($this->fetchComplianceRows($this->systemId));
        self::assertGreaterThan(0, $countBefore);

        // Update title on the same in-memory entity to trigger postUpdate.
        $system->setTitle('ПроекторОбновление-Updated-'.$suffix);
        $this->repo->save($system);

        $countAfter = count($this->fetchComplianceRows($this->systemId));
        self::assertSame($countBefore, $countAfter, 'После update строки должны пересчитаться (то же число).');
    }

    public function test_compliance_rows_cascade_deleted_with_system(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatmentId = Uuid::v7();
        $treatment = new SurfaceTreatment(
            $treatmentId,
            'Тестовая подготовка-каскад '.$suffix,
            'Sa 2½',
            'ISO 8501-1',
            Substrate::cases(),
        );
        $this->em->persist($treatment);
        $this->em->flush();

        [$coating] = $this->buildCoating($container, $suffix, CoatingBase::EP);

        $systemId = Uuid::v7();
        $system = new CoatingSystem(
            $systemId,
            'ПроекторКаскад-'.$suffix,
            '',
            Substrate::STEEL_GALVANIZED,
            $treatment,
        );
        $system->appendLayer($coating, 80);
        $this->repo->save($system);

        self::assertGreaterThan(0, count($this->fetchComplianceRows($systemId)));

        $this->em->clear();
        $loaded = $this->repo->findById($systemId);
        self::assertNotNull($loaded);
        $this->repo->remove($loaded);

        self::assertCount(0, $this->fetchComplianceRows($systemId));

        // Manual cleanup since tearDown's systemId is null for this test.
        $c = $this->em->find(Coating::class, $this->coatingId);
        if (null !== $c) {
            $this->em->remove($c);
        }
        $m = $this->em->find(Manufacturer::class, $this->manufacturerId);
        if (null !== $m) {
            $this->em->remove($m);
        }
        $this->em->flush();
        $t = $this->em->find(SurfaceTreatment::class, $treatmentId);
        if (null !== $t) {
            $this->em->remove($t);
            $this->em->flush();
        }

        // Prevent tearDown from trying to remove again.
        $this->systemId = null;
        $this->coatingId = null;
        $this->manufacturerId = null;
    }

    public function test_derived_fields_saved_to_aggregate_on_persist(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);
        [$coating] = $this->buildCoating($container, $suffix, CoatingBase::EP);

        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'ПоискПроектор-'.$suffix,
            'Тестовая система для поиска.',
            Substrate::STEEL_GALVANIZED,
            $treatment,
        );
        $system->appendLayer($coating, 80);
        $this->repo->save($system);

        $this->em->clear();
        $loaded = $this->em->find(CoatingSystem::class, $this->systemId);
        self::assertNotNull($loaded);
        // 1 слой → нет переходов → sum = 0.
        self::assertSame(0, $loaded->minBuildingTimeAt20Minutes());
        self::assertSame(5, $loaded->maxLayerApplicationMinTemp());

        $tsvector = $this->fetchSearchTsvector($this->systemId);
        self::assertNotNull($tsvector);
        self::assertNotSame('', $tsvector);
    }

    public function test_min_building_time_uses_linear_interpolation_and_skips_top_layer(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);
        [$coatingA] = $this->buildCoating($container, $suffix, CoatingBase::EP);
        $coatingB = $this->buildExtraCoating($container, $suffix.'-B', CoatingBase::EP, applicationMinTemp: 10, minRecoatMinutes: 480);

        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'СуммаИнтервалов-'.$suffix,
            '',
            Substrate::STEEL_GALVANIZED,
            $treatment,
        );
        // Слой A: tds=100, source_minutes=240, layer_dft=80 → LINEAR interpolate = 240*80/100 = 192.
        // Слой B — верхний, его интервал не участвует.
        $system->appendLayer($coatingA, 80);
        $system->appendLayer($coatingB, 80);
        $this->repo->save($system);

        $this->em->clear();
        $loaded = $this->em->find(CoatingSystem::class, $this->systemId);
        self::assertNotNull($loaded);
        self::assertSame(192, $loaded->minBuildingTimeAt20Minutes());
        // max(applicationMinTemp) = max(5, 10) = 10
        self::assertSame(10, $loaded->maxLayerApplicationMinTemp());
    }

    public function test_search_tsvector_contains_manufacturer_and_tag_titles(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);
        [$coating] = $this->buildCoating($container, $suffix, CoatingBase::EP);

        $tagSpec = $container->get(TagSpecification::class);
        $tag = new Tag('морской-'.$suffix, $tagSpec);
        $this->em->persist($tag);
        $this->em->flush();
        $this->tagIds = [$tag->getId()];

        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'ПоискТвектор-'.$suffix,
            'Описание системы.',
            Substrate::STEEL_GALVANIZED,
            $treatment,
        );
        $system->appendLayer($coating, 80);
        $system->replaceTags([$tag]);
        $this->repo->save($system);

        // Проверяем FTS-совпадение через явный to_tsquery, без ILIKE — иначе не проверим настоящий tsvector.
        $manufacturerMatches = (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM coating_system_search WHERE system_id = ? AND search_tsvector @@ plainto_tsquery('russian', 'мфрпроектор')",
            [(string) $this->systemId],
        );
        self::assertSame(1, $manufacturerMatches, 'tsvector должен содержать производителя слоя.');

        $tagMatches = (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM coating_system_search WHERE system_id = ? AND search_tsvector @@ plainto_tsquery('russian', 'морской')",
            [(string) $this->systemId],
        );
        self::assertSame(1, $tagMatches, 'tsvector должен содержать теги системы.');
    }

    public function test_search_row_upserted_on_update(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);
        [$coating] = $this->buildCoating($container, $suffix, CoatingBase::EP);

        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'ПоискUpsert-'.$suffix,
            '',
            Substrate::STEEL_GALVANIZED,
            $treatment,
        );
        $system->appendLayer($coating, 80);
        $this->repo->save($system);

        self::assertNotNull($this->fetchSearchTsvector($this->systemId));

        $system->setTitle('ПоискUpsert-NEW-'.$suffix);
        $this->repo->save($system);

        $rowsCount = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM coating_system_search WHERE system_id = ?',
            [(string) $this->systemId],
        );
        self::assertSame(1, $rowsCount, 'После update строка должна остаться одна (UPSERT).');
    }

    private function buildExtraCoating(
        object $container,
        string $suffix,
        CoatingBase $base,
        int $applicationMinTemp,
        int $minRecoatMinutes,
    ): Coating {
        $manufacturer = new Manufacturer(
            'МфрExtra-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->extraManufacturerIds[] = $manufacturer->getId();

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'ЛакExtra-'.$suffix,
            'Дополнительный слой.',
            50,
            1.5,
            $base,
            new DftRange(new PositiveNumberRange(60, 200), 100, ThicknessType::MIC),
            $applicationMinTemp,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 1440)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, $minRecoatMinutes))),
            null,
            1.0,
            null,
            $manufacturer,
            $container->get(CoatingSpecification::class),
        );
        $this->em->persist($coating);
        $this->em->flush();
        $this->extraCoatingIds[] = (string) $coatingId;

        return $coating;
    }

    private function fetchSearchTsvector(Uuid $systemId): ?string
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT search_tsvector::text FROM coating_system_search WHERE system_id = ?',
            [(string) $systemId],
        );

        return false === $value ? null : (string) $value;
    }

    /**
     * @return array{0: Coating}
     */
    private function buildCoating(object $container, string $suffix, CoatingBase $base): array
    {
        $manufacturer = new Manufacturer(
            'МфрПроектор-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'ЛакПроектор-'.$suffix,
            'Тестовое покрытие.',
            50,
            1.5,
            $base,
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

        return [$coating];
    }

    /** @return list<array<string,string>> */
    private function fetchComplianceRows(Uuid $systemId): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT standard, category, durability FROM coating_system_compliance WHERE system_id = ?',
            [(string) $systemId],
        );

        return $rows;
    }
}
