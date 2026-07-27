<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command;

use App\Coatings\Application\UseCase\Command\CreateCoatingSystem\CreateCoatingSystemCommand;
use App\Coatings\Application\UseCase\Command\CreateCoatingSystem\CreateCoatingSystemCommandHandler;
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
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CreateCoatingSystemTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private CreateCoatingSystemCommandHandler $handler;
    private EntityManagerInterface $em;

    private ?Uuid $systemId = null;
    private ?Uuid $coatingId = null;
    private ?Uuid $manufacturerId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(CreateCoatingSystemCommandHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);
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
            $this->cleanUpTreatment($em);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_create_coating_system_with_layers(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'Мфр-CS-Create-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Грунт-CS-Create-'.$suffix,
            'Описание тестового покрытия.',
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

        $cmd = new CreateCoatingSystemCommand(
            title: 'Система-CS-Create-'.$suffix,
            description: 'Тестовая система.',
            substrate: Substrate::STEEL_GALVANIZED,
            surfaceTreatmentId: $treatment->getId(),
            initialLayers: [
                ['coatingId' => (string) $coatingId, 'dft' => 80],
            ],
        );

        $result = ($this->handler)($cmd);

        self::assertNotEmpty($result->id);
        $this->systemId = Uuid::fromString($result->id);

        $this->em->clear();

        $loaded = $this->em->find(CoatingSystem::class, $this->systemId);
        self::assertNotNull($loaded, 'Система должна быть сохранена в БД.');
        self::assertSame('Система-CS-Create-'.$suffix, $loaded->getTitle());
        self::assertSame(Substrate::STEEL_GALVANIZED, $loaded->getSubstrate());
        self::assertSame(1, $loaded->layerCount());
        self::assertSame(1, $loaded->getLayers()->first()->getPosition());
        self::assertSame(80, $loaded->getLayers()->first()->getDft());

        // Verify compliance projector ran.
        $complianceRows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT category, durability FROM coating_system_compliance WHERE system_id = ?',
            [(string) $this->systemId],
        );
        self::assertGreaterThan(0, count($complianceRows), 'coating_system_compliance должен быть заполнен после создания.');
    }

    public function test_create_throws_when_coating_not_found(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $treatment = $this->createAndPersistTreatment($this->em, $suffix);
        $fakeId = (string) Uuid::v7();

        $cmd = new CreateCoatingSystemCommand(
            title: 'Система-notfound-'.$suffix,
            description: '',
            substrate: Substrate::CONCRETE,
            surfaceTreatmentId: $treatment->getId(),
            initialLayers: [
                ['coatingId' => $fakeId, 'dft' => 80],
            ],
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не найдено/');

        ($this->handler)($cmd);
    }
}
