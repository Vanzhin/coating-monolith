<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Query;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\UseCase\Query\FindCoatingSystemById\FindCoatingSystemByIdQuery;
use App\Coatings\Application\UseCase\Query\FindCoatingSystemById\FindCoatingSystemByIdQueryHandler;
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
use App\Coatings\Domain\Aggregate\Tag\Specification\TagSpecification;
use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class FindCoatingSystemByIdQueryHandlerTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private FindCoatingSystemByIdQueryHandler $handler;
    private EntityManagerInterface $em;

    private ?Uuid $systemId = null;
    private ?Uuid $coatingId = null;
    private ?Uuid $colorId = null;
    private ?Uuid $manufacturerId = null;
    /** @var list<string> */
    private array $tagIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(FindCoatingSystemByIdQueryHandler::class);
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
            if (null !== $this->colorId) {
                $color = $em->find(Color::class, $this->colorId);
                if (null !== $color) {
                    $em->remove($color);
                }
            }
            if (null !== $this->manufacturerId) {
                $m = $em->find(Manufacturer::class, $this->manufacturerId);
                if (null !== $m) {
                    $em->remove($m);
                }
            }
            foreach ($this->tagIds as $tagId) {
                $t = $em->find(Tag::class, $tagId);
                if (null !== $t) {
                    $em->remove($t);
                }
            }
            $em->flush();
            $this->tagIds = [];
            $this->cleanUpTreatment($em);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_returns_dto_for_existing_system(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'Мфр-FindById-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Грунт-FindById-'.$suffix,
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

        $color = new Color(Uuid::v7(), 'Серый-'.$suffix, null, '#888888');
        $this->em->persist($color);
        $this->colorId = $color->id;
        $coating->applyColorScheme(false, $color);

        $this->em->persist($coating);
        $this->em->flush();
        $this->coatingId = $coatingId;

        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'Система-FindById-'.$suffix,
            'Описание системы.',
            Substrate::STEEL_CARBON,
            $treatment,
        );
        $system->appendLayer($coating, 100, $color);
        $this->em->persist($system);
        $this->em->flush();

        $this->em->clear();

        $dto = ($this->handler)(new FindCoatingSystemByIdQuery((string) $this->systemId));

        self::assertInstanceOf(CoatingSystemDTO::class, $dto);
        self::assertSame((string) $this->systemId, $dto->id);
        self::assertSame('Система-FindById-'.$suffix, $dto->title);
        self::assertSame('Описание системы.', $dto->description);
        self::assertSame(Substrate::STEEL_CARBON->value, $dto->substrate);
        self::assertSame($treatment->getId(), $dto->surfaceTreatmentId);
        self::assertCount(1, $dto->layers);
        self::assertSame(100, $dto->totalDft);
    }

    public function test_dto_contains_tags_from_system(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'Мфр-FindTags-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Грунт-FindTags-'.$suffix,
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

        $color = new Color(Uuid::v7(), 'Серый-'.$suffix, null, '#888888');
        $this->em->persist($color);
        $this->colorId = $color->id;
        $coating->applyColorScheme(false, $color);

        $this->em->persist($coating);

        $tagSpec = $container->get(TagSpecification::class);
        $tag1 = new Tag('морской-'.$suffix, $tagSpec);
        $tag2 = new Tag('пищевой-'.$suffix, $tagSpec);
        $this->em->persist($tag1);
        $this->em->persist($tag2);
        $this->em->flush();
        $this->coatingId = $coatingId;
        $this->tagIds = [$tag1->getId(), $tag2->getId()];

        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'Система-FindTags-'.$suffix,
            '',
            Substrate::STEEL_CARBON,
            $treatment,
        );
        $system->appendLayer($coating, 100, $color);
        $system->replaceTags([$tag1, $tag2]);
        $this->em->persist($system);
        $this->em->flush();

        $this->em->clear();

        $dto = ($this->handler)(new FindCoatingSystemByIdQuery((string) $this->systemId));

        self::assertNotNull($dto);
        self::assertCount(2, $dto->tags);
        $tagTitles = array_map(fn ($t) => $t->title, $dto->tags);
        sort($tagTitles);
        self::assertSame(['морской-'.$suffix, 'пищевой-'.$suffix], $tagTitles);
    }

    public function test_returns_null_for_nonexistent_id(): void
    {
        $fakeId = (string) Uuid::v7();

        $result = ($this->handler)(new FindCoatingSystemByIdQuery($fakeId));

        self::assertNull($result);
    }
}
