<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Search;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Aggregate\Tag\Specification\TagSpecification;
use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Coatings\Infrastructure\Cache\CoatingSystemComplianceCacheRepository;
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
use App\Coatings\Infrastructure\Search\CoatingSystemFinder;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\RangeFilter;
use App\Shared\Domain\Repository\SearchResult;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Функциональные тесты CoatingSystemFinder — FTS, фасеты, сортировка, пагинация.
 * Каждый тест полностью изолирован: создаёт свои данные, пишет кэш, удаляет в tearDown.
 */
final class CoatingSystemFinderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoatingSystemFinder $finder;
    private CoatingSystemRepositoryInterface $repo;
    private CoatingSystemSearchCacheRepository $searchCache;
    private CoatingSystemComplianceCacheRepository $complianceCache;
    private ComplianceEvaluator $evaluator;
    private CoatingSpecification $coatingSpec;
    private ManufacturerSpecification $mfrSpec;
    private TagSpecification $tagSpec;

    /** @var list<Uuid> */
    private array $systemIds = [];
    /** @var list<Uuid> */
    private array $coatingIds = [];
    /** @var list<Uuid> */
    private array $mfrIds = [];
    /** @var list<Uuid> */
    private array $treatmentIds = [];
    /** @var list<string> */
    private array $tagIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->finder = $container->get(CoatingSystemFinder::class);
        $this->repo = $container->get(CoatingSystemRepositoryInterface::class);
        $this->searchCache = $container->get(CoatingSystemSearchCacheRepository::class);
        $this->complianceCache = $container->get(CoatingSystemComplianceCacheRepository::class);
        $this->evaluator = $container->get(ComplianceEvaluator::class);
        $this->coatingSpec = $container->get(CoatingSpecification::class);
        $this->mfrSpec = $container->get(ManufacturerSpecification::class);
        $this->tagSpec = $container->get(TagSpecification::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach ($this->systemIds as $id) {
                $s = $em->find(CoatingSystem::class, $id);
                if (null !== $s) {
                    $em->remove($s);
                }
            }
            $em->flush();
            foreach ($this->coatingIds as $id) {
                $c = $em->find(Coating::class, $id);
                if (null !== $c) {
                    $em->remove($c);
                }
            }
            foreach ($this->mfrIds as $id) {
                $m = $em->find(Manufacturer::class, $id);
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
            foreach ($this->treatmentIds as $id) {
                $t = $em->find(SurfaceTreatment::class, $id);
                if (null !== $t) {
                    $em->remove($t);
                    $em->flush();
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_fts_filter_finds_by_title_keyword(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $sysA = $this->buildAndSaveSystem('Эпоксидная Морская '.$suffix, Substrate::STEEL_CARBON);
        $sysB = $this->buildAndSaveSystem('Полиуретановая Обычная '.$suffix, Substrate::STEEL_CARBON);

        $filter = new CoatingSystemsFilter(search: SearchQuery::tryFromString('морская'));
        $result = $this->finder->find($filter);

        self::assertInstanceOf(SearchResult::class, $result);
        self::assertContains($sysA->getId(), $result->ids);
        self::assertNotContains($sysB->getId(), $result->ids);
    }

    public function test_substrate_filter_includes_matching_excludes_others(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $sysSteel = $this->buildAndSaveSystem('Steel sys '.$suffix, Substrate::STEEL_CARBON);
        $sysConcrete = $this->buildAndSaveSystem('Concrete sys '.$suffix, Substrate::CONCRETE);
        $sysZinc = $this->buildAndSaveSystem('Zinc sys '.$suffix, Substrate::STEEL_GALVANIZED);

        $filter = new CoatingSystemsFilter(substrates: [Substrate::STEEL_CARBON, Substrate::CONCRETE]);
        $result = $this->finder->find($filter);

        self::assertContains($sysSteel->getId(), $result->ids);
        self::assertContains($sysConcrete->getId(), $result->ids);
        self::assertNotContains($sysZinc->getId(), $result->ids);
    }

    public function test_compliance_standard_only_filter(): void
    {
        $suffix = bin2hex(random_bytes(4));
        // system with compliance populated
        $sysMatch = $this->buildAndSaveSystem('ISO sys '.$suffix, Substrate::STEEL_CARBON);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO coating_system_compliance (system_id, standard, category, durability) VALUES (:id, :std, :cat, :dur)',
            ['id' => $sysMatch->getId(), 'std' => ComplianceStandard::ISO_12944->value, 'cat' => 'C4', 'dur' => 'HIGH'],
        );
        // system without compliance
        $sysNoMatch = $this->buildAndSaveSystem('Plain sys '.$suffix, Substrate::STEEL_CARBON);

        $filter = new CoatingSystemsFilter(standard: ComplianceStandard::ISO_12944);
        $result = $this->finder->find($filter);

        self::assertContains($sysMatch->getId(), $result->ids);
        self::assertNotContains($sysNoMatch->getId(), $result->ids);
    }

    public function test_compliance_cascade_full_triple(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $sysMatch = $this->buildAndSaveSystem('ISO C4 HIGH '.$suffix, Substrate::STEEL_CARBON);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO coating_system_compliance (system_id, standard, category, durability) VALUES (:id, :std, :cat, :dur)',
            ['id' => $sysMatch->getId(), 'std' => ComplianceStandard::ISO_12944->value, 'cat' => 'C4', 'dur' => 'HIGH'],
        );
        $sysWrongCat = $this->buildAndSaveSystem('ISO C3 '.$suffix, Substrate::STEEL_CARBON);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO coating_system_compliance (system_id, standard, category, durability) VALUES (:id, :std, :cat, :dur)',
            ['id' => $sysWrongCat->getId(), 'std' => ComplianceStandard::ISO_12944->value, 'cat' => 'C3', 'dur' => 'HIGH'],
        );

        $filter = new CoatingSystemsFilter(
            standard: ComplianceStandard::ISO_12944,
            category: 'C4',
            durability: 'HIGH',
        );
        $result = $this->finder->find($filter);

        self::assertContains($sysMatch->getId(), $result->ids);
        self::assertNotContains($sysWrongCat->getId(), $result->ids);
    }

    public function test_compliance_filter_matches_stronger_categories_and_durabilities(): void
    {
        $suffix = bin2hex(random_bytes(4));
        // Система с максимумом C5-VERY_HIGH должна попадать под фильтр C3-HIGH.
        $sysStrong = $this->buildAndSaveSystem('C5 VH '.$suffix, Substrate::STEEL_CARBON);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO coating_system_compliance (system_id, standard, category, durability) VALUES (:id, :std, :cat, :dur)',
            ['id' => $sysStrong->getId(), 'std' => ComplianceStandard::ISO_12944->value, 'cat' => 'C5', 'dur' => 'VERY_HIGH'],
        );
        // Слабая система с C2-LOW не должна попадать под фильтр C3-HIGH.
        $sysWeak = $this->buildAndSaveSystem('C2 LOW '.$suffix, Substrate::STEEL_CARBON);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO coating_system_compliance (system_id, standard, category, durability) VALUES (:id, :std, :cat, :dur)',
            ['id' => $sysWeak->getId(), 'std' => ComplianceStandard::ISO_12944->value, 'cat' => 'C2', 'dur' => 'LOW'],
        );
        // Погружная категория Im2-VH не должна попадать под атмосферный фильтр C3.
        $sysImmersion = $this->buildAndSaveSystem('Im2 VH '.$suffix, Substrate::STEEL_CARBON);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO coating_system_compliance (system_id, standard, category, durability) VALUES (:id, :std, :cat, :dur)',
            ['id' => $sysImmersion->getId(), 'std' => ComplianceStandard::ISO_12944->value, 'cat' => 'Im2', 'dur' => 'VERY_HIGH'],
        );

        $filter = new CoatingSystemsFilter(
            standard: ComplianceStandard::ISO_12944,
            category: 'C3',
            durability: 'HIGH',
        );
        $result = $this->finder->find($filter);

        self::assertContains($sysStrong->getId(), $result->ids);
        self::assertNotContains($sysWeak->getId(), $result->ids);
        self::assertNotContains($sysImmersion->getId(), $result->ids);
    }

    public function test_tag_filter(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $tag = $this->persistTag('Морской '.$suffix);

        $sysWithTag = $this->buildAndSaveSystem('Tagged sys '.$suffix, Substrate::STEEL_CARBON, $tag);
        $sysNoTag = $this->buildAndSaveSystem('Plain sys '.$suffix, Substrate::STEEL_CARBON);

        $filter = new CoatingSystemsFilter(tagIds: [$tag->getId()]);
        $result = $this->finder->find($filter);

        self::assertContains($sysWithTag->getId(), $result->ids);
        self::assertNotContains($sysNoTag->getId(), $result->ids);
    }

    public function test_range_filter_min_application_time(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $sysFast = $this->buildAndSaveSystem('Fast sys '.$suffix, Substrate::STEEL_CARBON);
        $sysSlow = $this->buildAndSaveSystem('Slow sys '.$suffix, Substrate::STEEL_CARBON);

        // Overwrite cache directly with known values
        $this->em->getConnection()->executeStatement(
            'UPDATE coating_system_search SET min_application_time_at_20_minutes = :val WHERE system_id = :id',
            ['val' => 120, 'id' => $sysFast->getId()],
        );
        $this->em->getConnection()->executeStatement(
            'UPDATE coating_system_search SET min_application_time_at_20_minutes = :val WHERE system_id = :id',
            ['val' => 480, 'id' => $sysSlow->getId()],
        );

        $filter = new CoatingSystemsFilter(minApplicationTimeAt20: new RangeFilter(from: 0, to: 240));
        $result = $this->finder->find($filter);

        self::assertContains($sysFast->getId(), $result->ids);
        self::assertNotContains($sysSlow->getId(), $result->ids);
    }

    public function test_sort_title_asc(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $sysA = $this->buildAndSaveSystem('Aaa '.$suffix, Substrate::STEEL_CARBON);
        $sysB = $this->buildAndSaveSystem('Bbb '.$suffix, Substrate::STEEL_CARBON);

        $filter = new CoatingSystemsFilter(sort: CoatingSystemSort::TITLE_ASC);
        $result = $this->finder->find($filter);

        $posA = array_search($sysA->getId(), $result->ids, true);
        $posB = array_search($sysB->getId(), $result->ids, true);
        self::assertNotFalse($posA);
        self::assertNotFalse($posB);
        self::assertLessThan($posB, $posA, 'Aaa должна идти раньше Bbb при сортировке TITLE_ASC');
    }

    public function test_sort_title_desc(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $sysA = $this->buildAndSaveSystem('Aaa '.$suffix, Substrate::STEEL_CARBON);
        $sysB = $this->buildAndSaveSystem('Zzz '.$suffix, Substrate::STEEL_CARBON);

        $filter = new CoatingSystemsFilter(sort: CoatingSystemSort::TITLE_DESC);
        $result = $this->finder->find($filter);

        $posA = array_search($sysA->getId(), $result->ids, true);
        $posB = array_search($sysB->getId(), $result->ids, true);
        self::assertNotFalse($posA);
        self::assertNotFalse($posB);
        self::assertLessThan($posA, $posB, 'Zzz должна идти раньше Aaa при сортировке TITLE_DESC');
    }

    public function test_pagination_respects_pager(): void
    {
        $suffix = bin2hex(random_bytes(4));
        for ($i = 1; $i <= 5; ++$i) {
            $this->buildAndSaveSystem("Sys-{$i}-{$suffix}", Substrate::STEEL_CARBON);
        }

        $filter = new CoatingSystemsFilter(
            sort: CoatingSystemSort::TITLE_ASC,
            pager: Pager::fromPage(1, 2),
        );
        $result = $this->finder->find($filter);

        self::assertCount(2, $result->ids);
        self::assertGreaterThanOrEqual(5, $result->total);
    }

    public function test_total_is_independent_of_pagination(): void
    {
        $suffix = bin2hex(random_bytes(4));
        for ($i = 1; $i <= 3; ++$i) {
            $this->buildAndSaveSystem("Pager-{$i}-{$suffix}", Substrate::STEEL_CARBON);
        }

        $filter = new CoatingSystemsFilter(
            search: SearchQuery::tryFromString('Pager '.$suffix),
            sort: CoatingSystemSort::DEFAULT,
            pager: Pager::fromPage(1, 1),
        );
        $result = $this->finder->find($filter);

        self::assertCount(1, $result->ids);
        self::assertSame(3, $result->total);
    }

    /**
     * Сохраняет CoatingSystem в БД и наполняет кэш `coating_system_search`.
     * Опциональный $tag добавляется через pivot-таблицу coating_system_tag.
     */
    private function buildAndSaveSystem(string $title, Substrate $substrate, ?Tag $tag = null): CoatingSystem
    {
        $mfr = $this->persistManufacturer();
        $coating = $this->persistCoating($mfr);
        $treatment = $this->persistTreatment();

        $id = Uuid::v7();
        $system = new CoatingSystem($id, $title, 'Описание для '.$title, $substrate, $treatment);
        $system->appendLayer($coating, 80);
        $this->repo->save($system);

        if (null !== $tag) {
            $system->replaceTags([$tag]);
            $this->em->flush();
            $this->em->getConnection()->executeStatement(
                'INSERT INTO coating_system_tag (coating_system_id, tag_id) VALUES (:sys, :tag) ON CONFLICT DO NOTHING',
                ['sys' => $system->getId(), 'tag' => $tag->getId()],
            );
        }

        $this->em->clear();

        // Reload because clear detaches the entity.
        $loaded = $this->repo->findById($id);
        \assert(null !== $loaded);
        $this->searchCache->upsert($loaded);

        $this->systemIds[] = $id;

        return $loaded;
    }

    private function persistManufacturer(): Manufacturer
    {
        $mfr = new Manufacturer('Mfr-'.bin2hex(random_bytes(3)), $this->mfrSpec);
        $this->em->persist($mfr);
        $this->em->flush();
        $this->mfrIds[] = Uuid::fromString($mfr->getId());

        return $mfr;
    }

    private function persistCoating(Manufacturer $mfr): Coating
    {
        $id = UuidService::generateUuid();
        $coating = new Coating(
            $id,
            'Coating-'.bin2hex(random_bytes(3)),
            'Test coating.',
            5,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(60, 200), 80, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 1440)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 120))),
            null,
            1.0,
            null,
            $mfr,
            $this->coatingSpec,
        );
        $this->em->persist($coating);
        $this->em->flush();
        $this->coatingIds[] = $id;

        return $coating;
    }

    private function persistTreatment(): SurfaceTreatment
    {
        $id = Uuid::v7();
        $treatment = new SurfaceTreatment(
            $id,
            'Treatment-'.bin2hex(random_bytes(3)),
            'Sa-'.bin2hex(random_bytes(2)),
            null,
            Substrate::cases(),
        );
        $this->em->persist($treatment);
        $this->em->flush();
        $this->treatmentIds[] = $id;

        return $treatment;
    }

    private function persistTag(string $title): Tag
    {
        $tag = new Tag($title, $this->tagSpec, Tag::TYPE_GENERAL);
        $this->em->persist($tag);
        $this->em->flush();
        $this->tagIds[] = $tag->getId();

        return $tag;
    }
}
