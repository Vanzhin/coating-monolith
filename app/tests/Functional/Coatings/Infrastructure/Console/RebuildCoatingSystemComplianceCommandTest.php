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
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidator;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\CoatingSystem\SurfacePreparation;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Infrastructure\Repository\CoatingSystemRepository;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class RebuildCoatingSystemComplianceCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private EntityManagerInterface $em;
    private CoatingSystemRepository $repo;
    private CoatingSystemChainValidator $chainValidator;

    private ?Uuid $systemId = null;
    private ?Uuid $coatingId = null;
    private ?Uuid $manufacturerId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $kernel = static::$kernel;
        $container = static::getContainer();

        $application = new Application($kernel);
        $command = $application->find('coatings:system-compliance:rebuild');
        $this->tester = new CommandTester($command);

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = new CoatingSystemRepository($this->em);
        $this->chainValidator = new CoatingSystemChainValidator();
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
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_truncates_garbage_and_rebuilds_valid_compliance(): void
    {
        $container = static::getContainer();
        $conn = $this->em->getConnection();
        $suffix = bin2hex(random_bytes(3));

        // Create a manufacturer
        $manufacturer = new Manufacturer(
            'Мфр-cmd-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        // Create a coating
        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Лак-cmd-'.$suffix,
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
        $this->coatingId = $coatingId;

        // Create a coating system
        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'Система-cmd-'.$suffix,
            'Тестовая система.',
            Substrate::STEEL_CARBON,
            new SurfacePreparation('Sa 2½', 'Очистка', 'ISO 8501-1'),
            $this->chainValidator,
        );
        $system->appendLayer($coating, 80);
        $this->repo->save($system);

        $systemIdStr = (string) $this->systemId;

        // Clear identity map to allow fresh loads
        $this->em->clear();

        // Insert garbage rows that should be truncated
        $conn->executeStatement(
            'INSERT INTO coating_system_compliance (system_id, standard, category, durability)
             VALUES (:id, :std, :cat, :dur)',
            [
                'id' => $systemIdStr,
                'std' => 'GARBAGE_STANDARD',
                'cat' => 'GARBAGE_CAT',
                'dur' => 'GARBAGE_DUR',
            ],
        );

        // Verify garbage was inserted
        $garbageCount = $conn->fetchOne(
            'SELECT COUNT(*) FROM coating_system_compliance WHERE standard = :std',
            ['std' => 'GARBAGE_STANDARD'],
        );
        self::assertGreaterThan(0, (int) $garbageCount, 'Garbage should be inserted before rebuild');

        // Execute command
        $exitCode = $this->tester->execute([]);
        self::assertSame(0, $exitCode, 'Command should succeed');

        // Check that garbage is gone
        $garbageCount = $conn->fetchOne(
            'SELECT COUNT(*) FROM coating_system_compliance WHERE standard = :std',
            ['std' => 'GARBAGE_STANDARD'],
        );
        self::assertSame('0', (string) $garbageCount, 'Garbage should be truncated');

        // Check that the output message is correct
        $output = $this->tester->getDisplay();
        self::assertStringContainsString('Rebuilt compliance for', $output);
    }
}
