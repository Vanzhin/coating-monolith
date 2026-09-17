<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Audit;

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
use App\Shared\Domain\Audit\AuditEntry;
use App\Shared\Domain\Audit\AuditPolicyInterface;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AuditOnFlushListenerTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    public function testUpdatingCoatingTitleWritesAuditRow(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(AuditPolicyInterface::class)->invalidate(Coating::class);

        $coating = $this->createPersistedCoating($em);
        $id = $coating->getId();

        $coating->setTitle('Аудит-проба '.substr(md5((string) mt_rand()), 0, 6));
        $em->flush();

        $rows = $em->getRepository(AuditEntry::class)->findBy(
            ['entityClass' => Coating::class, 'entityId' => $id],
            ['occurredAt' => 'DESC'],
            5,
        );
        self::assertNotEmpty($rows);
        self::assertSame('system', $rows[0]->actorId());
        self::assertContains('title', array_map(static fn ($c) => $c->path, $rows[0]->changes()->all()));
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
            'Тестовое покрытие для аудита.',
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
