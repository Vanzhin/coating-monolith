<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Query;

use App\Coatings\Application\UseCase\Query\SearchCoatingSystemsByCompliance\SearchCoatingSystemsByComplianceQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystemsByCompliance\SearchCoatingSystemsByComplianceQueryHandler;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidator;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Тест для SearchCoatingSystemsByComplianceQuery.
 *
 * Используем правило ISO 12944, C3, HIGH, STEEL_CARBON + ZINC_RICH:
 *   mnoc >= 2, ndft >= 160, primer EP (isZincRich=true), followup EP/PUR/AY.
 *
 * Совпадающая система: 2 слоя, итого 160 мкм, EP-Zn-rich грунт + EP финиш.
 * Несовпадающая: CONCRETE substrate — не попадает под C3/STEEL_CARBON.
 */
final class SearchCoatingSystemsByComplianceQueryHandlerTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private SearchCoatingSystemsByComplianceQueryHandler $handler;
    private EntityManagerInterface $em;
    private CoatingSystemChainValidator $chainValidator;

    /** @var list<Uuid> */
    private array $systemIds = [];
    /** @var list<Uuid> */
    private array $coatingIds = [];
    /** @var list<Uuid> */
    private array $manufacturerIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(SearchCoatingSystemsByComplianceQueryHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->chainValidator = new CoatingSystemChainValidator();
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
            foreach ($this->coatingIds as $id) {
                $c = $em->find(Coating::class, $id);
                if (null !== $c) {
                    $em->remove($c);
                }
            }
            foreach ($this->manufacturerIds as $id) {
                $m = $em->find(Manufacturer::class, $id);
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

    public function test_search_returns_only_matching_system(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'Мфр-Compliance-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerIds[] = Uuid::fromString($manufacturer->getId());

        // Zn-rich EP primer (isZincRich=true, CoatingBase::EP)
        $primerId = UuidService::generateUuid();
        $primer = new Coating(
            $primerId,
            'EP-Грунт-Zn-'.$suffix,
            'Цинкнаполненный эпоксидный грунт.',
            60,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(60, 200), 80, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 1440)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 240))),
            null,
            1.0,
            null,
            $manufacturer,
            $container->get(CoatingSpecification::class),
            50,
            true, // isZincRich
        );
        $this->em->persist($primer);
        $this->coatingIds[] = $primerId;

        // EP topcoat (followup EP/PUR/AY allowed by rule)
        $topcoatId = UuidService::generateUuid();
        $topcoat = new Coating(
            $topcoatId,
            'EP-Финиш-'.$suffix,
            'Эпоксидный финишный слой.',
            60,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(60, 200), 80, ThicknessType::MIC),
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
        $this->em->persist($topcoat);
        $this->coatingIds[] = $topcoatId;

        // Concrete coating for non-matching system
        $concreteCoatingId = UuidService::generateUuid();
        $concreteCoating = new Coating(
            $concreteCoatingId,
            'Бетон-Грунт-'.$suffix,
            'Грунтовка для бетона.',
            50,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(60, 200), 80, ThicknessType::MIC),
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
        $this->em->persist($concreteCoating);
        $this->coatingIds[] = $concreteCoatingId;

        $this->em->flush();

        // Matching: STEEL_CARBON, 2 layers (Zn-rich primer 80 + EP topcoat 80 = 160 µm)
        $matchId = Uuid::v7();
        $matchingSystem = new CoatingSystem(
            $matchId,
            'Система-C3-HIGH-'.$suffix,
            'Система ISO 12944 C3 HIGH.',
            Substrate::STEEL_CARBON,
            $treatment,
            $this->chainValidator,
        );
        $matchingSystem->appendLayer($primer, 80);
        $matchingSystem->appendLayer($topcoat, 80);
        $this->em->persist($matchingSystem);
        $this->systemIds[] = $matchId;

        // Non-matching: CONCRETE substrate — не попадает под C3/STEEL_CARBON
        $nonMatchId = Uuid::v7();
        $nonMatchingSystem = new CoatingSystem(
            $nonMatchId,
            'Система-Бетон-'.$suffix,
            'Система для бетона (не попадает в C3/STEEL_CARBON).',
            Substrate::CONCRETE,
            $treatment,
            $this->chainValidator,
        );
        $nonMatchingSystem->appendLayer($concreteCoating, 80);
        $this->em->persist($nonMatchingSystem);
        $this->systemIds[] = $nonMatchId;

        $this->em->flush();
        $this->em->clear();

        $result = ($this->handler)(new SearchCoatingSystemsByComplianceQuery(
            standard: ComplianceStandard::ISO_12944,
            category: 'C3',
            durability: 'HIGH',
            substrate: Substrate::STEEL_CARBON,
            page: 1,
            perPage: 10,
        ));

        $ids = array_map(static fn ($dto) => $dto->id, $result['items']);

        self::assertContains((string) $matchId, $ids, 'Совпадающая система должна быть в результате.');
        self::assertNotContains((string) $nonMatchId, $ids, 'Несовпадающая система не должна быть в результате.');
        self::assertGreaterThanOrEqual(1, $result['total']);
    }

    public function test_search_returns_empty_for_nonexistent_compliance(): void
    {
        $result = ($this->handler)(new SearchCoatingSystemsByComplianceQuery(
            standard: ComplianceStandard::ISO_12944,
            category: 'CX',
            durability: 'VERY_HIGH',
            substrate: Substrate::ALUMINUM,
            page: 1,
            perPage: 10,
        ));

        self::assertSame(0, $result['total']);
        self::assertCount(0, $result['items']);
    }
}
