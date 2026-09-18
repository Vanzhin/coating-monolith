<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CoatingRepositoryAllForSuggestTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoatingRepositoryInterface $repo;
    /** @var list<string> */
    private array $createdCoatingIds = [];
    private string $manufacturerId;

    protected function setUp(): void
    {
        parent::setUp();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = $container->get(CoatingRepositoryInterface::class);

        $suffix = uniqid('', true);

        /** @var ManufacturerSpecification $manufacturerSpec */
        $manufacturerSpec = $container->get(ManufacturerSpecification::class);
        $manufacturer = new Manufacturer('AllForSuggestMfr_'.$suffix, $manufacturerSpec);
        $this->em->persist($manufacturer);
        $this->manufacturerId = $manufacturer->getId();

        /** @var CoatingSpecification $coatingSpec */
        $coatingSpec = $container->get(CoatingSpecification::class);

        // Два покрытия с намеренно обратным алфавиту порядком создания — проверяем сортировку по title.
        foreach (['ZZZ AllForSuggest '.$suffix, 'AAA AllForSuggest '.$suffix] as $title) {
            $coating = new Coating(
                UuidService::generateUuid(),
                $title,
                'desc',
                60,
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
                $coatingSpec,
            );
            $this->em->persist($coating);
            $this->createdCoatingIds[] = $coating->getId();
        }

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ($this->createdCoatingIds as $id) {
                $coating = $em->find(Coating::class, Uuid::fromString($id));
                if (null !== $coating) {
                    $em->remove($coating);
                }
            }
            $manufacturer = $em->find(Manufacturer::class, Uuid::fromString($this->manufacturerId));
            if (null !== $manufacturer) {
                $em->remove($manufacturer);
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_returns_all_coatings_including_created(): void
    {
        $all = $this->repo->allForSuggest();

        self::assertGreaterThanOrEqual(2, count($all));
        self::assertContainsOnlyInstancesOf(Coating::class, $all);

        $ids = array_map(static fn (Coating $c): string => $c->getId(), $all);
        foreach ($this->createdCoatingIds as $created) {
            self::assertContains($created, $ids);
        }
    }

    public function test_result_is_ordered_by_title(): void
    {
        // Порядок проверяем только среди своих двух (ASCII: «AAA…» до «ZZZ…»),
        // чтобы не зависеть от коллации чужих фикстур.
        $ids = array_map(static fn (Coating $c): string => $c->getId(), $this->repo->allForSuggest());

        $posAaa = array_search($this->createdCoatingIds[1], $ids, true); // «AAA …» создан вторым
        $posZzz = array_search($this->createdCoatingIds[0], $ids, true); // «ZZZ …» создан первым

        self::assertIsInt($posAaa);
        self::assertIsInt($posZzz);
        self::assertLessThan($posZzz, $posAaa, '«AAA …» должно идти раньше «ZZZ …» при сортировке по title');
    }
}
