<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\Service;

use App\Coatings\Application\Service\CoatingSystemOperatingTemperatureCalculator;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\ThermalExposureLimits;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
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

final class CoatingSystemOperatingTemperatureCalculatorTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private EntityManagerInterface $em;
    private CoatingSystemOperatingTemperatureCalculator $calculator;

    /** @var list<Uuid> */
    private array $coatingIds = [];
    private ?Uuid $systemId = null;
    private ?Uuid $manufacturerId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->calculator = $c->get(CoatingSystemOperatingTemperatureCalculator::class);
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
            foreach ($this->coatingIds as $cid) {
                $co = $em->find(Coating::class, $cid);
                if (null !== $co) {
                    $em->remove($co);
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

    public function test_dry_heat_falls_back_to_base_default_and_immersion_stays_null(): void
    {
        $ep = $this->persistCoating(CoatingBase::EP, null, null);
        $system = $this->persistSystem($ep);

        $snap = $this->calculator->calculate($system);

        self::assertSame(120, $snap->dryHeatContinuousMax);
        self::assertSame(120, $snap->dryHeatPeakMax);
        self::assertNull($snap->immersionContinuousMax);
        self::assertNull($snap->immersionPeakMax);
    }

    public function test_dry_heat_is_weakest_link_min_across_layers(): void
    {
        $ep = $this->persistCoating(CoatingBase::EP, null, null); // дефолт 120
        $ay = $this->persistCoating(CoatingBase::AY, null, null); // дефолт 50
        $system = $this->persistSystem($ep, $ay);

        $snap = $this->calculator->calculate($system);

        self::assertSame(50, $snap->dryHeatContinuousMax);
        self::assertSame(50, $snap->dryHeatPeakMax);
    }

    public function test_immersion_is_min_when_all_layers_documented(): void
    {
        $ep = $this->persistCoating(CoatingBase::EP, null, new ThermalExposureLimits(null, 60, 80));
        $ay = $this->persistCoating(CoatingBase::AY, null, new ThermalExposureLimits(null, 40));
        $system = $this->persistSystem($ep, $ay);

        $snap = $this->calculator->calculate($system);

        self::assertSame(40, $snap->immersionContinuousMax); // min(60, 40)
        self::assertSame(40, $snap->immersionPeakMax);       // min(80, 40 (peak?? continuous))
    }

    public function test_immersion_null_when_any_layer_missing_immersion(): void
    {
        $ep = $this->persistCoating(CoatingBase::EP, null, new ThermalExposureLimits(null, 60));
        $ay = $this->persistCoating(CoatingBase::AY, null, null); // погружение не задокументировано
        $system = $this->persistSystem($ep, $ay);

        $snap = $this->calculator->calculate($system);

        self::assertNull($snap->immersionContinuousMax);
        self::assertNull($snap->immersionPeakMax);
    }

    // -----------------------------------------------------------------------

    private function persistCoating(CoatingBase $base, ?ThermalExposureLimits $dry, ?ThermalExposureLimits $imm): Coating
    {
        $c = static::getContainer();
        $suffix = bin2hex(random_bytes(4));

        if (null === $this->manufacturerId) {
            $manufacturer = new Manufacturer('МфрТемпТест-'.$suffix, $c->get(ManufacturerSpecification::class));
            $this->em->persist($manufacturer);
            $this->em->flush();
            $this->manufacturerId = Uuid::fromString($manufacturer->getId());
        } else {
            $manufacturer = $this->em->find(Manufacturer::class, $this->manufacturerId);
        }

        $id = UuidService::generateUuid();
        $coating = new Coating(
            $id,
            'ЛакТемп-'.$suffix,
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
            $c->get(CoatingSpecification::class),
        );
        $coating->setDryHeatExposure($dry);
        $coating->setImmersionExposure($imm);
        $this->em->persist($coating);
        $this->em->flush();
        $this->coatingIds[] = $id;

        return $coating;
    }

    private function persistSystem(Coating ...$layers): CoatingSystem
    {
        $suffix = bin2hex(random_bytes(4));
        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'СистТемп-'.$suffix,
            'Описание.',
            Substrate::STEEL_GALVANIZED,
            $treatment,
        );
        foreach ($layers as $layer) {
            $system->appendLayer($layer, 80);
        }
        $this->em->persist($system);
        $this->em->flush();

        return $system;
    }
}
