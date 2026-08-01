<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command;

use App\Coatings\Application\UseCase\Command\RemoveCoatingSystem\RemoveCoatingSystemCommand;
use App\Coatings\Application\UseCase\Command\RemoveCoatingSystem\RemoveCoatingSystemCommandHandler;
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
use App\Coatings\Infrastructure\Repository\CoatingSystemRepository;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RemoveCoatingSystemTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private RemoveCoatingSystemCommandHandler $handler;
    private EntityManagerInterface $em;
    private CoatingSystemRepository $repo;

    private ?Uuid $coatingId = null;
    private ?Uuid $manufacturerId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(RemoveCoatingSystemCommandHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = new CoatingSystemRepository($this->em);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
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

    public function test_remove_deletes_system(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'Мфр-CS-Remove-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Грунт-CS-Remove-'.$suffix,
            'Описание.',
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

        $systemId = Uuid::v7();
        $system = new CoatingSystem(
            $systemId,
            'Система-CS-Remove-'.$suffix,
            'Описание.',
            Substrate::STEEL_CARBON,
            $treatment,
        );
        $system->appendLayer($coating, 80);
        $this->repo->save($system);
        $this->em->clear();

        $result = ($this->handler)(new RemoveCoatingSystemCommand((string) $systemId));

        $this->em->clear();
        self::assertNull($this->repo->findById($systemId), 'Система должна быть удалена из БД.');

        // Compliance rows must be cascade-deleted.
        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM coating_system_compliance WHERE system_id = ?',
            [(string) $systemId],
        );
        self::assertSame('0', (string) $count, 'coating_system_compliance должны быть удалены каскадом.');
    }

    public function test_remove_throws_when_system_not_found(): void
    {
        $fakeId = (string) Uuid::v7();

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не найдена/');

        ($this->handler)(new RemoveCoatingSystemCommand($fakeId));
    }
}
