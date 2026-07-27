<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command\Layer;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidator;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Infrastructure\Repository\CoatingSystemRepository;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Shared fixture for layer mutation handler functional tests.
 *
 * Creates: one Manufacturer, one Coating (EP, DFT 60-200), one CoatingSystem with one initial layer (position=1, dft=80).
 */
trait CoatingSystemLayerTestFixtureTrait
{
    private ?Uuid $systemId = null;
    private ?Uuid $coatingId = null;
    private ?Uuid $manufacturerId = null;
    private ?Uuid $treatmentId = null;

    private function setUpFixture(ContainerInterface $container, EntityManagerInterface $em): void
    {
        $suffix = bin2hex(random_bytes(4));

        $treatmentId = Uuid::v7();
        $treatment = new SurfaceTreatment(
            $treatmentId,
            'Тестовая подготовка поверхности '.$suffix,
            'Sa 2½',
            'ISO 8501-1',
            Substrate::cases(),
        );
        $em->persist($treatment);
        $this->treatmentId = $treatmentId;

        $manufacturer = new Manufacturer(
            'Мфр-Layer-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Грунт-Layer-'.$suffix,
            'Описание тестового слоя.',
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
        $em->persist($coating);
        $em->flush();
        $this->coatingId = $coatingId;

        $systemId = Uuid::v7();
        $chainValidator = new CoatingSystemChainValidator();
        $system = new CoatingSystem(
            $systemId,
            'Система-Layer-'.$suffix,
            'Тестовая система для мутаций слоёв.',
            Substrate::STEEL_CARBON,
            $treatment,
            $chainValidator,
        );
        $system->appendLayer($coating, 80);

        $repo = new CoatingSystemRepository($em);
        $repo->save($system);
        $this->systemId = $systemId;
    }

    private function tearDownFixture(EntityManagerInterface $em): void
    {
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
            if (null !== $this->treatmentId) {
                $t = $em->find(SurfaceTreatment::class, $this->treatmentId);
                if (null !== $t) {
                    $em->remove($t);
                    $em->flush();
                }
                $this->treatmentId = null;
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
    }
}
