<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Search;

use App\Coatings\Application\Service\CoatingSystemOperatingTemperatureCalculator;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\Color\Color;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
use App\Coatings\Infrastructure\Search\CoatingSystemFinder;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * applyFts: инфикс по title через ILIKE не должен сужать поиск систем относительно
 * прежнего suggest-эндпоинта (который делал FTS OR title ILIKE). Покрывает:
 *  - инфикс внутри слова, который FTS-префикс принципиально не матчит;
 *  - отсутствие регресса обычного стеммированного FTS-совпадения.
 */
final class CoatingSystemFinderFtsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoatingSystemFinder $finder;
    private CoatingSystemRepositoryInterface $repo;
    private CoatingSystemSearchCacheRepository $searchCache;
    private CoatingSystemOperatingTemperatureCalculator $temperatureCalculator;
    private CoatingSpecification $coatingSpec;
    private ManufacturerSpecification $mfrSpec;

    /** @var list<Uuid> */
    private array $systemIds = [];
    /** @var list<Uuid> */
    private array $coatingIds = [];
    /** @var list<Uuid> */
    private array $mfrIds = [];
    /** @var list<Uuid> */
    private array $treatmentIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->finder = $container->get(CoatingSystemFinder::class);
        $this->repo = $container->get(CoatingSystemRepositoryInterface::class);
        $this->searchCache = $container->get(CoatingSystemSearchCacheRepository::class);
        $this->temperatureCalculator = $container->get(CoatingSystemOperatingTemperatureCalculator::class);
        $this->coatingSpec = $container->get(CoatingSpecification::class);
        $this->mfrSpec = $container->get(ManufacturerSpecification::class);
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

    /**
     * Title содержит слово «Аналоговая» — запрос «налог» — подстрока в СЕРЕДИНЕ слова
     * (не с начала). Префиксный tsquery («налог:*») не матчит токен «аналогов» (стеммер
     * режет с начала слова), поэтому FTS-ветка промахивается; раньше при непустом tsquery
     * это означало полный мисс. Теперь OR title ILIKE должен подхватить инфикс.
     */
    public function test_infix_title_match_found(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $sysInfix = $this->buildAndSaveSystem('ЭП-1234-Аналоговая защита '.$suffix, Substrate::STEEL_CARBON);
        $sysOther = $this->buildAndSaveSystem('Полиуретановая обычная '.$suffix, Substrate::STEEL_CARBON);

        $filter = new CoatingSystemsFilter(search: SearchQuery::tryFromString('налог'));
        $result = $this->finder->find($filter);

        self::assertContains($sysInfix->getId(), $result->ids);
        self::assertNotContains($sysOther->getId(), $result->ids);
    }

    /** Обычный стеммированный FTS-запрос не должен сломаться добавлением OR ILIKE. */
    public function test_fts_stemmed_match_still_found(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $sysEpoxy = $this->buildAndSaveSystem('Эпоксидная морская '.$suffix, Substrate::STEEL_CARBON);
        $sysOther = $this->buildAndSaveSystem('Полиуретановая обычная '.$suffix, Substrate::STEEL_CARBON);

        $filter = new CoatingSystemsFilter(search: SearchQuery::tryFromString('эпоксидн'));
        $result = $this->finder->find($filter);

        self::assertContains($sysEpoxy->getId(), $result->ids);
        self::assertNotContains($sysOther->getId(), $result->ids);
    }

    private function buildAndSaveSystem(string $title, Substrate $substrate): CoatingSystem
    {
        $mfr = $this->persistManufacturer();
        $coating = $this->persistCoating($mfr);
        $treatment = $this->persistTreatment();

        $id = Uuid::v7();
        $system = new CoatingSystem($id, $title, 'Описание для '.$title, $substrate, $treatment);
        $color = new Color(Uuid::v7(), 'Серый-'.bin2hex(random_bytes(4)), null, '#888888');
        $this->em->persist($color);
        $coating->applyColorScheme(true);
        $system->appendLayer($coating, 80, $color);
        $this->repo->save($system);

        $this->em->clear();

        $loaded = $this->repo->findById($id);
        \assert(null !== $loaded);
        $this->searchCache->upsert($loaded, $this->temperatureCalculator->calculate($loaded));

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
}
