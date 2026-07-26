<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Query;

use App\Coatings\Application\UseCase\Query\ListCoatingSystems\ListCoatingSystemsQuery;
use App\Coatings\Application\UseCase\Query\ListCoatingSystems\ListCoatingSystemsQueryHandler;
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
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ListCoatingSystemsQueryHandlerTest extends KernelTestCase
{
    private ListCoatingSystemsQueryHandler $handler;
    private EntityManagerInterface $em;
    private CoatingSystemChainValidator $chainValidator;

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
        $this->handler = $container->get(ListCoatingSystemsQueryHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->chainValidator = new CoatingSystemChainValidator();
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
            foreach ($this->coatingIds as $id) {
                $c = $em->find(Coating::class, $id);
                if (null !== $c) {
                    $em->remove($c);
                }
            }
            foreach ($this->manufacturerIds as $id) {
                $m = $em->find(Manufacturer::class, $id);
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

    public function test_list_filters_by_substrate_and_returns_items_and_total(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $manufacturer = new Manufacturer(
            $suffix.'-Мфр-List',
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerIds[] = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            $suffix.'-Грунт-List',
            'Покрытие для теста list.',
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

        // System 1: STEEL_CARBON
        $id1 = Uuid::v7();
        $s1 = new CoatingSystem(
            $id1,
            $suffix.'-СистемаList-A',
            'Описание A.',
            Substrate::STEEL_CARBON,
            new SurfacePreparation('Sa 2½', 'Дробеструйная очистка'),
            $this->chainValidator,
        );
        $s1->appendLayer($coating, 80);
        $this->em->persist($s1);
        $this->systemIds[] = $id1;

        // System 2: STEEL_CARBON
        $id2 = Uuid::v7();
        $s2 = new CoatingSystem(
            $id2,
            $suffix.'-СистемаList-B',
            'Описание B.',
            Substrate::STEEL_CARBON,
            new SurfacePreparation('Sa 2½', 'Дробеструйная очистка'),
            $this->chainValidator,
        );
        $s2->appendLayer($coating, 80);
        $this->em->persist($s2);
        $this->systemIds[] = $id2;

        // System 3: CONCRETE (must be excluded by substrate filter)
        $id3 = Uuid::v7();
        $s3 = new CoatingSystem(
            $id3,
            $suffix.'-СистемаList-C',
            'Описание C.',
            Substrate::CONCRETE,
            new SurfacePreparation('CS 1', 'Ручная очистка'),
            $this->chainValidator,
        );
        $s3->appendLayer($coating, 80);
        $this->em->persist($s3);
        $this->systemIds[] = $id3;

        $this->em->flush();
        $this->em->clear();

        $filter = new CoatingSystemsFilter(
            titleLike: $suffix.'-СистемаList',
            substrate: Substrate::STEEL_CARBON,
        );
        $result = ($this->handler)(new ListCoatingSystemsQuery($filter, 1, 10));

        self::assertSame(2, $result['total']);
        self::assertCount(2, $result['items']);

        $ids = array_map(static fn ($dto) => $dto->id, $result['items']);
        self::assertContains((string) $id1, $ids);
        self::assertContains((string) $id2, $ids);
    }

    public function test_list_returns_empty_when_no_match(): void
    {
        $filter = new CoatingSystemsFilter(titleLike: 'НЕСУЩЕСТВУЮЩИЙ_ЗАГОЛОВОК_'.bin2hex(random_bytes(8)));
        $result = ($this->handler)(new ListCoatingSystemsQuery($filter, 1, 10));

        self::assertSame(0, $result['total']);
        self::assertCount(0, $result['items']);
    }
}
