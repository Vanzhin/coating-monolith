<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Console;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Infrastructure\Console\RebuildCoatingSystemSearchCacheCommand;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class RebuildCoatingSystemSearchCacheCommandTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private EntityManagerInterface $em;
    private Connection $conn;

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
        $this->em = $container->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
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
            $em->flush();
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

    public function test_command_backfills_both_cache_tables_for_all_systems(): void
    {
        $sysA = $this->buildSystemAndPersist();
        $sysB = $this->buildSystemAndPersist();

        $this->conn->executeStatement('DELETE FROM coating_system_search WHERE system_id IN (?, ?)', [
            $sysA->getId(),
            $sysB->getId(),
        ]);
        $this->conn->executeStatement('DELETE FROM coating_system_compliance WHERE system_id IN (?, ?)', [
            $sysA->getId(),
            $sysB->getId(),
        ]);

        $tester = new CommandTester(
            static::getContainer()->get(RebuildCoatingSystemSearchCacheCommand::class),
        );
        $tester->execute([]);

        foreach ([$sysA, $sysB] as $s) {
            self::assertNotFalse($this->conn->fetchAssociative(
                'SELECT * FROM coating_system_search WHERE system_id = ?',
                [$s->getId()],
            ));
        }
        self::assertSame(0, $tester->getStatusCode());
    }

    // -----------------------------------------------------------------------

    private function buildSystemAndPersist(): CoatingSystem
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'МфрБэкфилл-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $manufacturerId = Uuid::fromString($manufacturer->getId());
        $this->manufacturerIds[] = $manufacturerId;

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'ЛакБэкфилл-'.$suffix,
            'Тестовое покрытие.',
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
        $this->em->persist($coating);
        $this->em->flush();
        $this->coatingIds[] = $coatingId;

        $systemId = Uuid::v7();
        $system = new CoatingSystem(
            $systemId,
            'СистемаБэкфилл-'.$suffix,
            'Описание для поиска.',
            Substrate::STEEL_CARBON,
            $treatment,
        );
        $system->appendLayer($coating, 80);
        $this->em->persist($system);
        $this->em->flush();
        $this->systemIds[] = $systemId;

        return $system;
    }
}
