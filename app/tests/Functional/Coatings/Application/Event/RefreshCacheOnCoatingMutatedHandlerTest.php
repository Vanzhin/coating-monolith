<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\Event;

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
use App\Coatings\Domain\Event\CoatingMutated;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RefreshCacheOnCoatingMutatedHandlerTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private EntityManagerInterface $em;
    private Connection $conn;
    private EventBusInterface $eventBus;

    /** @var list<Uuid> */
    private array $systemIds = [];
    private ?Uuid $coatingId = null;
    private ?Uuid $manufacturerId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $this->eventBus = $container->get(EventBusInterface::class);
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
                    $em->flush();
                }
            }
            if (null !== $this->coatingId) {
                $c = $em->find(Coating::class, $this->coatingId);
                if (null !== $c) {
                    $em->remove($c);
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

    public function test_dispatch_updates_all_systems_with_that_coating(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'МфрКотМутd-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'ЛакКотМутd-'.$suffix,
            'Покрытие для теста RefreshCacheOnCoatingMutated.',
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
        $this->coatingId = $coatingId;

        // Build two systems both containing that coating
        $sysAId = Uuid::v7();
        $sysA = new CoatingSystem(
            $sysAId,
            'СистемаKотМутdA-'.$suffix,
            'Система А для теста CoatingMutated.',
            Substrate::STEEL_CARBON,
            $treatment,
        );
        $this->em->persist($sysA);
        $this->em->flush();
        $color = new Color(Uuid::v7(), 'Серый-'.$suffix, null, '#888888');
        $this->em->persist($color);
        $coating->applyColorScheme(true);
        $sysA->appendLayer($coating, 80, $color);
        $this->em->flush();
        $this->systemIds[] = $sysAId;

        $sysBId = Uuid::v7();
        $sysB = new CoatingSystem(
            $sysBId,
            'СистемаKотМутdB-'.$suffix,
            'Система Б для теста CoatingMutated.',
            Substrate::STEEL_GALVANIZED,
            $treatment,
        );
        $this->em->persist($sysB);
        $this->em->flush();
        $sysB->appendLayer($coating, 80, $color);
        $this->em->flush();
        $this->systemIds[] = $sysBId;

        // Manually delete cache rows to ensure the handler populates them
        $this->conn->executeStatement(
            'DELETE FROM coating_system_search WHERE system_id IN (?, ?)',
            [(string) $sysAId, (string) $sysBId],
        );

        $this->eventBus->execute(new CoatingMutated((string) $coatingId));

        foreach ([$sysAId, $sysBId] as $id) {
            $row = $this->conn->fetchAssociative(
                'SELECT * FROM coating_system_search WHERE system_id = ?',
                [(string) $id],
            );
            self::assertNotFalse($row, sprintf('Система %s должна иметь строку в coating_system_search.', $id));
        }
    }
}
