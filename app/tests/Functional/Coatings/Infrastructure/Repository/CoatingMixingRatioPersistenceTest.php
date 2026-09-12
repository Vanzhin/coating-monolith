<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\MixingRatio;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PartsRatio;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Персистентность соотношения смешивания через реальный Doctrine-стек: MixingRatioType (DBAL),
 * jsonb-колонка mixing_ratio, ORM-маппинг. Сохраняем покрытие с объёмным и массовым
 * соотношением, перечитываем из чистого EM и сверяем, что VO восстановился и считает.
 */
final class CoatingMixingRatioPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoatingRepositoryInterface $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = $container->get(CoatingRepositoryInterface::class);
    }

    public function test_mixing_ratio_survives_persist_and_reload(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(2));

        $manufacturer = new Manufacturer('Производитель'.$suffix, $container->get(ManufacturerSpecification::class));
        $this->em->persist($manufacturer);

        $coating = new Coating(
            UuidService::generateUuid(),
            'Образец'.$suffix,
            'Двухкомпонентное покрытие.',
            50,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(80, 150), 100, ThicknessType::MIC),
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
        $coating->setMixingRatio(new MixingRatio(
            byVolume: new PartsRatio(new PositiveNumber(3.0), new PositiveNumber(1.0)),
            byMass: new PartsRatio(new PositiveNumber(100.0), new PositiveNumber(23.0)),
        ));
        $this->repo->add($coating);
        $this->em->flush();
        $id = $coating->getId();

        $this->em->clear();

        $reloaded = $this->em->find(Coating::class, Uuid::fromString($id));
        self::assertNotNull($reloaded);

        $ratio = $reloaded->getMixingRatio();
        self::assertNotNull($ratio);
        $byVolume = $ratio->getByVolume();
        $byMass = $ratio->getByMass();
        self::assertNotNull($byVolume);
        self::assertNotNull($byMass);
        self::assertSame([3.0, 1.0], $byVolume->getParts());
        self::assertSame([100.0, 23.0], $byMass->getParts());

        // Восстановленный VO считает: 30 мл основы по объёму -> 10 мл отвердителя.
        self::assertEqualsWithDelta([10.0], $byVolume->fromBase(30.0)->getAdditions(), 1e-9);
    }

    public function test_null_mixing_ratio_survives_persist_and_reload(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(2));

        $manufacturer = new Manufacturer('Производитель'.$suffix, $container->get(ManufacturerSpecification::class));
        $this->em->persist($manufacturer);

        $coating = new Coating(
            UuidService::generateUuid(),
            'Однокомпонент'.$suffix,
            'Однокомпонентное покрытие — смешивать нечего.',
            50,
            1.5,
            CoatingBase::AK,
            new DftRange(new PositiveNumberRange(80, 150), 100, ThicknessType::MIC),
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
        $this->repo->add($coating);
        $this->em->flush();
        $id = $coating->getId();

        $this->em->clear();

        $reloaded = $this->em->find(Coating::class, Uuid::fromString($id));
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getMixingRatio());
    }
}
