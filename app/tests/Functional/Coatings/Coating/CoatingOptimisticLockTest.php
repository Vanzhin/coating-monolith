<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Coating;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CoatingOptimisticLockTest extends KernelTestCase
{
    public function test_stale_version_throws(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $coating = $this->createPersistedCoating($em);
        $id = $coating->getId();

        $coating->setTitle('Версия A '.substr(md5((string) mt_rand()), 0, 6));
        $em->flush();

        $fresh = $em->find(Coating::class, Uuid::fromString($id));
        self::assertNotNull($fresh);

        $this->expectException(OptimisticLockException::class);
        $em->lock($fresh, LockMode::OPTIMISTIC, 1); // ждём устаревшую версию 1 → конфликт
    }

    /** Собирает и сохраняет минимальное валидное покрытие — тестовая БД без фикстур. */
    private function createPersistedCoating(EntityManagerInterface $em): Coating
    {
        $suffix = substr(md5((string) mt_rand()), 0, 8);

        /** @var ManufacturerSpecification $manufacturerSpec */
        $manufacturerSpec = self::getContainer()->get(ManufacturerSpecification::class);
        $manufacturer = new Manufacturer('TestManufacturer_'.$suffix, $manufacturerSpec);
        $em->persist($manufacturer);

        $rootDefault = new DryingTimeSeries(new TimeAtTemperature(20, 240));
        $minTree = new RecoatingIntervalTree($rootDefault);
        $touchSeries = new DryingTimeSeries(new TimeAtTemperature(20, 60));
        $cureSeries = new DryingTimeSeries(new TimeAtTemperature(20, 1440));

        /** @var CoatingSpecification $coatingSpec */
        $coatingSpec = self::getContainer()->get(CoatingSpecification::class);

        $coating = new Coating(
            UuidService::generateUuid(),
            'TestCoating_'.$suffix,
            'Тестовое покрытие для оптимистичной блокировки.',
            50,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(80, 150), 100, ThicknessType::MIC),
            5,
            $touchSeries,
            $cureSeries,
            $minTree,
            null,
            1.0,
            null,
            $manufacturer,
            $coatingSpec,
        );
        $em->persist($coating);
        $em->flush();

        return $coating;
    }
}
