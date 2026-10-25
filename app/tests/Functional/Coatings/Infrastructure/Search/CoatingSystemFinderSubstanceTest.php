<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Search;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
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
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
use App\Coatings\Infrastructure\Search\CoatingSystemFinder;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Функциональные тесты фасета химстойкости в CoatingSystemFinder: система матчится,
 * только если у неё нет ни одного слоя без suitable-оценки по КАЖДОМУ выбранному веществу
 * (AND по веществам), опционально ограниченному температурой испытания.
 */
final class CoatingSystemFinderSubstanceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoatingSystemFinder $finder;
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
        $this->finder = $container->get(CoatingSystemFinder::class);
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

    public function test_all_layers_suitable_matches(): void
    {
        $ethanol = $this->createSubstance('Этанол');
        $coating = $this->persistCoating($this->persistManufacturer());
        $this->createAssessment($coating->getId(), $ethanol, Grade::R);
        $s1 = $this->buildAndSaveSystemWithCoatings('S1 все слои стойки', Substrate::STEEL_CARBON, $coating->getId());

        $result = $this->finder->find($this->filter(substanceIds: [$ethanol]));

        self::assertContains($s1->getId(), $result->ids->getList());
    }

    public function test_one_layer_missing_assessment_excluded(): void
    {
        $ethanol = $this->createSubstance('Этанол');
        $coatingWithAssessment = $this->persistCoating($this->persistManufacturer());
        $this->createAssessment($coatingWithAssessment->getId(), $ethanol, Grade::R);
        $coatingWithoutAssessment = $this->persistCoating($this->persistManufacturer());
        // S2: один слой стоек, второй — вообще без оценки по этанолу.
        $s2 = $this->buildAndSaveSystemWithCoatings(
            'S2 слой без оценки',
            Substrate::STEEL_CARBON,
            $coatingWithAssessment->getId(),
            $coatingWithoutAssessment->getId(),
        );

        $ids = $this->finder->find($this->filter(substanceIds: [$ethanol]))->ids->getList();

        self::assertNotContains($s2->getId(), $ids);
    }

    public function test_one_layer_unsuitable_grade_excluded(): void
    {
        $ethanol = $this->createSubstance('Этанол');
        $coatingR = $this->persistCoating($this->persistManufacturer());
        $this->createAssessment($coatingR->getId(), $ethanol, Grade::R);
        $coatingNR = $this->persistCoating($this->persistManufacturer());
        $this->createAssessment($coatingNR->getId(), $ethanol, Grade::NR);
        // S3: один слой стоек (R), второй — нестоек (NR) к этанолу.
        $s3 = $this->buildAndSaveSystemWithCoatings(
            'S3 слой NR',
            Substrate::STEEL_CARBON,
            $coatingR->getId(),
            $coatingNR->getId(),
        );

        $ids = $this->finder->find($this->filter(substanceIds: [$ethanol]))->ids->getList();

        self::assertNotContains($s3->getId(), $ids);
    }

    public function test_temperature_narrows(): void
    {
        $ethanol = $this->createSubstance('Этанол');

        $coatingHighTemp = $this->persistCoating($this->persistManufacturer());
        $this->createAssessment($coatingHighTemp->getId(), $ethanol, Grade::R, 100);
        $s1 = $this->buildAndSaveSystemWithCoatings('S1 темп 100', Substrate::STEEL_CARBON, $coatingHighTemp->getId());

        $coatingLowTemp = $this->persistCoating($this->persistManufacturer());
        $this->createAssessment($coatingLowTemp->getId(), $ethanol, Grade::R, 40);
        $s4 = $this->buildAndSaveSystemWithCoatings('S4 темп 40', Substrate::STEEL_CARBON, $coatingLowTemp->getId());

        $ids = $this->finder->find($this->filter(substanceIds: [$ethanol], temperature: 60))->ids->getList();

        self::assertContains($s1->getId(), $ids);
        self::assertNotContains($s4->getId(), $ids);
    }

    public function test_multiple_substances_are_anded(): void
    {
        $ethanol = $this->createSubstance('Этанол');
        $water = $this->createSubstance('Вода');

        $coatingBoth = $this->persistCoating($this->persistManufacturer());
        $this->createAssessment($coatingBoth->getId(), $ethanol, Grade::R);
        $this->createAssessment($coatingBoth->getId(), $water, Grade::R);
        $sysBoth = $this->buildAndSaveSystemWithCoatings('Стоек к обоим', Substrate::STEEL_CARBON, $coatingBoth->getId());

        $coatingOnlyEthanol = $this->persistCoating($this->persistManufacturer());
        $this->createAssessment($coatingOnlyEthanol->getId(), $ethanol, Grade::R);
        // нет оценки по воде вообще
        $sysOnlyEthanol = $this->buildAndSaveSystemWithCoatings('Стоек только к этанолу', Substrate::STEEL_CARBON, $coatingOnlyEthanol->getId());

        $ids = $this->finder->find($this->filter(substanceIds: [$ethanol, $water]))->ids->getList();

        self::assertContains($sysBoth->getId(), $ids);
        self::assertNotContains($sysOnlyEthanol->getId(), $ids);
    }

    public function test_system_without_layers_not_vacuously_matched(): void
    {
        $ethanol = $this->createSubstance('Этанол');
        $s5 = $this->buildAndSaveSystemWithCoatings('S5 без слоёв', Substrate::STEEL_CARBON);

        $ids = $this->finder->find($this->filter(substanceIds: [$ethanol]))->ids->getList();

        self::assertNotContains($s5->getId(), $ids);
    }

    public function test_no_substance_filter_leaves_results_unchanged(): void
    {
        $coating = $this->persistCoating($this->persistManufacturer());
        $sys = $this->buildAndSaveSystemWithCoatings('Без фасета химстойкости', Substrate::STEEL_CARBON, $coating->getId());

        $withoutFacet = $this->finder->find($this->filter(substanceIds: []));

        self::assertContains($sys->getId(), $withoutFacet->ids->getList());
    }

    /**
     * @param list<string> $substanceIds
     */
    private function filter(array $substanceIds, ?int $temperature = null): CoatingSystemsFilter
    {
        return new CoatingSystemsFilter(
            substanceIds: new StringCollection(...$substanceIds),
            substanceTemperature: null !== $temperature ? new PositiveNumber($temperature) : null,
        );
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

    private function createAssessment(string $coatingId, string $substanceId, Grade $grade, ?int $maxTemperature = null): void
    {
        $id = Uuid::v4();
        $assessment = new Assessment(
            $id,
            Uuid::fromString($coatingId),
            Uuid::fromString($substanceId),
            $grade,
            null !== $maxTemperature ? AssessmentTemperature::fromInt($maxTemperature) : null,
            new StringCollection(),
            self::getContainer()->get(AssessmentSpecification::class),
        );
        $this->assessmentRepo->add($assessment);
        $this->assessmentIds[] = $id;
    }

    /**
     * Система с заданными покрытиями (по слою на каждое) — для проверки фасета химстойкости.
     * Без coatingIds создаёт систему без слоёв (для теста «без слоёв не матчится вакуумно»).
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
}
