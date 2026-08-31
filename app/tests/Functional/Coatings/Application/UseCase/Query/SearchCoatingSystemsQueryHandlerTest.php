<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Query;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\Service\CoatingSystemOperatingTemperatureCalculator;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryHandler;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
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
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SearchCoatingSystemsQueryHandlerTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private SearchCoatingSystemsQueryHandler $handler;
    private EntityManagerInterface $em;
    private CoatingSystemRepositoryInterface $repo;
    private CoatingSystemSearchCacheRepository $searchCache;
    private CoatingSystemOperatingTemperatureCalculator $temperatureCalculator;

    /** @var list<Uuid> */
    private array $systemIds = [];
    /** @var list<Uuid> */
    private array $coatingIds = [];
    /** @var list<Uuid> */
    private array $colorIds = [];
    /** @var list<Uuid> */
    private array $manufacturerIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(SearchCoatingSystemsQueryHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = $container->get(CoatingSystemRepositoryInterface::class);
        $this->searchCache = $container->get(CoatingSystemSearchCacheRepository::class);
        $this->temperatureCalculator = $container->get(CoatingSystemOperatingTemperatureCalculator::class);
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
            foreach ($this->colorIds as $id) {
                $color = $em->find(Color::class, $id);
                if (null !== $color) {
                    $em->remove($color);
                }
            }
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

    public function test_returns_dto_list_with_total(): void
    {
        $sys = $this->persistSystem('SearchQH-'.bin2hex(random_bytes(3)));

        $result = ($this->handler)(new SearchCoatingSystemsQuery(new CoatingSystemsFilter()));

        self::assertInstanceOf(SearchCoatingSystemsQueryResult::class, $result);
        self::assertGreaterThanOrEqual(1, $result->total);
        $ids = array_map(static fn (CoatingSystemDTO $dto) => $dto->id, $result->items);
        self::assertContains($sys->getId(), $ids);
    }

    public function test_search_by_title_returns_matching_system(): void
    {
        $marker = $this->randomWord();
        $sys = $this->persistSystem('Эпоксидная '.$marker);

        $filter = new CoatingSystemsFilter(search: SearchQuery::tryFromString($marker));
        $result = ($this->handler)(new SearchCoatingSystemsQuery($filter));

        self::assertInstanceOf(SearchCoatingSystemsQueryResult::class, $result);
        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
        self::assertSame($sys->getId(), $result->items[0]->id);
    }

    public function test_result_items_are_coating_system_dtos(): void
    {
        $this->persistSystem('DTO-'.bin2hex(random_bytes(3)));

        $result = ($this->handler)(new SearchCoatingSystemsQuery(new CoatingSystemsFilter()));

        foreach ($result->items as $item) {
            self::assertInstanceOf(CoatingSystemDTO::class, $item);
        }
    }

    /**
     * Случайное латинское слово фиксированной длины — стабильный FTS-маркер.
     * Раньше тут был hex-суффикс (bin2hex): смешанный буквенно-цифровой токен
     * russian-FTS раскладывал через раз (напр. «370e24f8» → float 370e24 + f8),
     * поиск иногда матчил 0 — флаки-тест. Зеркалит CoatingSystemFinderTest::randomWord.
     */
    private function randomWord(int $length = 12): string
    {
        $letters = 'abcdefghijklmnopqrstuvwxyz';
        $word = '';
        for ($i = 0; $i < $length; ++$i) {
            $word .= $letters[random_int(0, 25)];
        }

        return $word;
    }

    private function persistSystem(string $title): CoatingSystem
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(2));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $mfr = new Manufacturer('Mfr-'.$suffix, $container->get(ManufacturerSpecification::class));
        $this->em->persist($mfr);
        $this->em->flush();
        $this->manufacturerIds[] = Uuid::fromString($mfr->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Coating-'.$suffix,
            'Test coating.',
            5,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(60, 200), 80, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 1440)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 120))),
            null,
            1.0,
            null,
            $mfr,
            $container->get(CoatingSpecification::class),
        );

        $color = new Color(Uuid::v7(), 'Серый-'.$suffix, null, '#888888');
        $this->em->persist($color);
        $this->colorIds[] = $color->id;
        $coating->applyColorScheme(false, $color);

        $this->em->persist($coating);
        $this->em->flush();
        $this->coatingIds[] = $coatingId;

        $id = Uuid::v7();
        $system = new CoatingSystem($id, $title, 'Описание системы '.$title, Substrate::STEEL_CARBON, $treatment);
        $system->appendLayer($coating, 80, $color);
        $this->repo->save($system);
        $this->em->clear();

        $loaded = $this->repo->findById($id);
        \assert(null !== $loaded);
        $this->searchCache->upsert($loaded, $this->temperatureCalculator->calculate($loaded));

        $this->systemIds[] = $id;

        return $loaded;
    }
}
