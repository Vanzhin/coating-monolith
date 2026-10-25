<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Repository;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Infrastructure\Repository\AssessmentRepository;
use App\ChemicalResistance\Infrastructure\Repository\SubstanceRepository;
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
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Repository\PaginationResult;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Единая точка CoatingSystemRepository::findByFilter: find() через CoatingSystemFinder
 * (id + total на read-side) → findByIds (hydration, порядок сохранён) → PaginationResult.
 * Репозиторий берём из контейнера — проверяем реальное DI-склеивание (autowire finder).
 */
final class CoatingSystemRepositoryFindByFilterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoatingSystemRepositoryInterface $repo;
    private CoatingSystemSearchCacheRepository $searchCache;
    private CoatingSystemOperatingTemperatureCalculator $temperatureCalculator;
    private CoatingSpecification $coatingSpec;
    private ManufacturerSpecification $mfrSpec;
    private AssessmentRepository $assessmentRepo;
    private SubstanceRepository $substanceRepo;

    /** @var list<Uuid> */
    private array $systemIds = [];
    /** @var list<Uuid> */
    private array $coatingIds = [];
    /** @var list<Uuid> */
    private array $mfrIds = [];
    /** @var list<Uuid> */
    private array $treatmentIds = [];
    /** @var list<Uuid> */
    private array $assessmentIds = [];
    /** @var list<Uuid> */
    private array $substanceIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = $container->get(CoatingSystemRepositoryInterface::class);
        $this->searchCache = $container->get(CoatingSystemSearchCacheRepository::class);
        $this->temperatureCalculator = $container->get(CoatingSystemOperatingTemperatureCalculator::class);
        $this->coatingSpec = $container->get(CoatingSpecification::class);
        $this->mfrSpec = $container->get(ManufacturerSpecification::class);
        $this->assessmentRepo = $container->get(AssessmentRepository::class);
        $this->substanceRepo = $container->get(SubstanceRepository::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach ($this->assessmentIds as $id) {
                $a = $em->find(Assessment::class, $id);
                if (null !== $a) {
                    $em->remove($a);
                }
            }
            $em->flush();
            foreach ($this->substanceIds as $id) {
                $s = $em->find(Substance::class, $id);
                if (null !== $s) {
                    $em->remove($s);
                }
            }
            $em->flush();
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

    public function test_find_by_filter_returns_hydrated_entities_in_finder_order(): void
    {
        $marker = $this->randomWord();
        $sysMatch = $this->buildAndSaveSystem("Эпоксидная {$marker} Защита", Substrate::STEEL_CARBON);
        $sysOther = $this->buildAndSaveSystem('Полиуретановая Обычная '.bin2hex(random_bytes(4)), Substrate::STEEL_CARBON);

        $result = $this->repo->findByFilter(new CoatingSystemsFilter(search: SearchQuery::tryFromString($marker)));

        self::assertInstanceOf(PaginationResult::class, $result);
        self::assertContainsOnlyInstancesOf(CoatingSystem::class, $result->items);

        $ids = array_map(static fn (CoatingSystem $s) => $s->getId(), $result->items);
        self::assertContains($sysMatch->getId(), $ids);
        self::assertNotContains($sysOther->getId(), $ids);
    }

    public function test_find_by_filter_narrows_by_substance_resistance(): void
    {
        $ethanol = $this->createSubstance('Этанол');

        $resistantCoating = $this->persistCoating($this->persistManufacturer());
        $this->createAssessment($resistantCoating->getId(), $ethanol, Grade::R);
        $sysResistant = $this->buildAndSaveSystemWithCoatings('Стоек к этанолу', Substrate::STEEL_CARBON, $resistantCoating->getId());

        $notResistantCoating = $this->persistCoating($this->persistManufacturer());
        $this->createAssessment($notResistantCoating->getId(), $ethanol, Grade::NR);
        $sysNotResistant = $this->buildAndSaveSystemWithCoatings('Не стоек к этанолу', Substrate::STEEL_CARBON, $notResistantCoating->getId());

        $result = $this->repo->findByFilter(new CoatingSystemsFilter(substanceIds: new StringCollection($ethanol)));

        $ids = array_map(static fn (CoatingSystem $s) => $s->getId(), $result->items);
        self::assertContains($sysResistant->getId(), $ids);
        self::assertNotContains($sysNotResistant->getId(), $ids);
    }

    /** Случайное латинское слово фиксированной длины — стабильный FTS-маркер для тестов. */
    private function randomWord(int $length = 12): string
    {
        $letters = 'abcdefghijklmnopqrstuvwxyz';
        $word = '';
        for ($i = 0; $i < $length; ++$i) {
            $word .= $letters[random_int(0, 25)];
        }

        return $word;
    }

    /**
     * Сохраняет CoatingSystem в БД и наполняет кэш `coating_system_search`.
     */
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

    /**
     * Система с заданными покрытиями (по слою на каждое) — для проверки фасета химстойкости.
     */
    private function buildAndSaveSystemWithCoatings(string $title, Substrate $substrate, string ...$coatingIds): CoatingSystem
    {
        $treatment = $this->persistTreatment();

        $id = Uuid::v7();
        $system = new CoatingSystem($id, $title, 'Описание для '.$title, $substrate, $treatment);
        $color = new Color(Uuid::v7(), 'Серый-'.bin2hex(random_bytes(4)), null, '#888888');
        $this->em->persist($color);
        foreach ($coatingIds as $coatingId) {
            $coating = $this->em->find(Coating::class, $coatingId);
            \assert($coating instanceof Coating);
            $coating->applyColorScheme(true);
            $system->appendLayer($coating, 80, $color);
        }
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

    private function createSubstance(string $canonicalName): string
    {
        $id = Uuid::v4();
        $substance = new Substance(
            $id,
            $canonicalName.'-'.bin2hex(random_bytes(4)),
            null,
            new StringCollection(),
            self::getContainer()->get(SubstanceSpecification::class),
        );
        $this->substanceRepo->add($substance);
        $this->substanceIds[] = $id;

        return $id->toRfc4122();
    }

    private function createAssessment(string $coatingId, string $substanceId, Grade $grade): void
    {
        $id = Uuid::v4();
        $assessment = new Assessment(
            $id,
            Uuid::fromString($coatingId),
            Uuid::fromString($substanceId),
            $grade,
            null,
            new StringCollection(),
            self::getContainer()->get(AssessmentSpecification::class),
        );
        $this->assessmentRepo->add($assessment);
        $this->assessmentIds[] = $id;
    }
}
