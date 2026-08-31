<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command;

use App\Coatings\Application\UseCase\Command\CreateCoatingSystem\CreateCoatingSystemCommand;
use App\Coatings\Application\UseCase\Command\CreateCoatingSystem\CreateCoatingSystemCommandHandler;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
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
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CreateCoatingSystemTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;
    use AuthenticatesActorTrait;

    private CreateCoatingSystemCommandHandler $handler;
    private EntityManagerInterface $em;

    private ?Uuid $systemId = null;
    private ?Uuid $coatingId = null;
    private ?Uuid $manufacturerId = null;
    /** @var list<string> */
    private array $tagIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(CreateCoatingSystemCommandHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);

        $this->authenticateAsSystem();
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
        $coating->applyColorScheme(true);
        $color = new Color(Uuid::v7(), 'Серый-'.$suffix, null, '#888888');
        $this->em->persist($coating);
        $this->em->persist($color);
        $this->em->flush();
        $this->coatingId = $coatingId;

        $cmd = new CreateCoatingSystemCommand(
            title: 'Система-CS-Create-'.$suffix,
            description: 'Тестовая система.',
            substrate: Substrate::STEEL_GALVANIZED,
            environment: EnvironmentType::Atmospheric,
            surfaceTreatmentId: $treatment->getId(),
            initialLayers: [
                ['coatingId' => (string) $coatingId, 'dft' => 80, 'colorId' => (string) $color->getId()],
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

    public function test_create_populates_coating_system_search_cache(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'Мфр-CS-SearchCache-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Грунт-CS-SearchCache-'.$suffix,
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
        $coating->applyColorScheme(true);
        $color = new Color(Uuid::v7(), 'Серый-'.$suffix, null, '#888888');
        $this->em->persist($coating);
        $this->em->persist($color);
        $this->em->flush();
        $this->coatingId = $coatingId;

        $cmd = new CreateCoatingSystemCommand(
            title: 'Система-CS-SearchCache-'.$suffix,
            description: 'Тестовая система.',
            substrate: Substrate::STEEL_GALVANIZED,
            environment: EnvironmentType::Atmospheric,
            surfaceTreatmentId: $treatment->getId(),
            initialLayers: [
                ['coatingId' => (string) $coatingId, 'dft' => 80, 'colorId' => (string) $color->getId()],
            ],
        );

        $result = ($this->handler)($cmd);
        $this->systemId = Uuid::fromString($result->id);

        $this->em->clear();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT system_id FROM coating_system_search WHERE system_id = ?',
            [(string) $this->systemId],
        );
        self::assertNotFalse($row, 'coating_system_search должна содержать строку для новой системы после создания.');
    }

    public function test_create_persists_tags_via_m2m(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'Мфр-CS-CreateTags-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Грунт-CS-CreateTags-'.$suffix,
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
        $coating->applyColorScheme(true);
        $color = new Color(Uuid::v7(), 'Серый-'.$suffix, null, '#888888');
        $this->em->persist($coating);
        $this->em->persist($color);

        $tagSpec = $container->get(TagSpecification::class);
        $tag1 = new Tag('морской-'.$suffix, $tagSpec);
        $tag2 = new Tag('пищевой-'.$suffix, $tagSpec);
        $this->em->persist($tag1);
        $this->em->persist($tag2);
        $this->em->flush();
        $this->coatingId = $coatingId;
        $this->tagIds = [$tag1->getId(), $tag2->getId()];

        $cmd = new CreateCoatingSystemCommand(
            title: 'Система-CS-CreateTags-'.$suffix,
            description: '',
            substrate: Substrate::STEEL_CARBON,
            environment: EnvironmentType::Atmospheric,
            surfaceTreatmentId: $treatment->getId(),
            initialLayers: [
                ['coatingId' => (string) $coatingId, 'dft' => 80, 'colorId' => (string) $color->getId()],
            ],
            tagIds: new StringCollection($tag1->getId(), $tag2->getId()),
        );

        $result = ($this->handler)($cmd);
        $this->systemId = Uuid::fromString($result->id);

        $this->em->clear();

        // Проверка через сырой SQL — минуем ORM lazy-load, гарантированно смотрим в join-таблицу.
        $joinRows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT tag_id FROM coating_system_tag WHERE coating_system_id = ? ORDER BY tag_id',
            [(string) $this->systemId],
        );
        self::assertCount(2, $joinRows);
        $persistedTagIds = array_column($joinRows, 'tag_id');
        sort($persistedTagIds);
        $expected = [$tag1->getId(), $tag2->getId()];
        sort($expected);
        self::assertSame($expected, $persistedTagIds);

        // И через агрегат — теги должны догружаться LAZY-load'ом.
        $loaded = $this->em->find(CoatingSystem::class, $this->systemId);
        self::assertNotNull($loaded);
        self::assertCount(2, $loaded->getTags());
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
            environment: EnvironmentType::Atmospheric,
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
