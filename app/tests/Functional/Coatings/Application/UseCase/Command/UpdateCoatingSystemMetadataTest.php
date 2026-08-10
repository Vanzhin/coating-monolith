<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Command;

use App\Coatings\Application\UseCase\Command\UpdateCoatingSystemMetadata\UpdateCoatingSystemMetadataCommand;
use App\Coatings\Application\UseCase\Command\UpdateCoatingSystemMetadata\UpdateCoatingSystemMetadataCommandHandler;
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
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Aggregate\Tag\Specification\TagSpecification;
use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Coatings\Infrastructure\Repository\CoatingSystemRepository;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class UpdateCoatingSystemMetadataTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private UpdateCoatingSystemMetadataCommandHandler $handler;
    private EntityManagerInterface $em;
    private CoatingSystemRepository $repo;

    private ?Uuid $systemId = null;
    private ?Uuid $coatingId = null;
    private ?Uuid $manufacturerId = null;
    private ?Uuid $secondTreatmentId = null;
    /** @var list<string> */
    private array $tagIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(UpdateCoatingSystemMetadataCommandHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = new CoatingSystemRepository($this->em);
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
            if (null !== $this->secondTreatmentId) {
                $t2 = $em->find(SurfaceTreatment::class, $this->secondTreatmentId);
                if (null !== $t2) {
                    $em->remove($t2);
                    $em->flush();
                }
                $this->secondTreatmentId = null;
            }
            $this->cleanUpTreatment($em);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_update_metadata_persists_changes(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'Мфр-CS-Update-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Грунт-CS-Update-'.$suffix,
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

        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'Система-CS-Update-'.$suffix,
            'Описание до.',
            Substrate::STEEL_CARBON,
            $treatment,
        );
        $system->appendLayer($coating, 80);
        $this->repo->save($system);

        $cmd = new UpdateCoatingSystemMetadataCommand(
            id: (string) $this->systemId,
            title: 'Система-CS-Update-НОВЫЙ-'.$suffix,
            description: 'Описание после.',
            substrate: Substrate::CONCRETE,
            environment: EnvironmentType::Atmospheric,
            surfaceTreatmentId: $treatment->getId(),
        );

        $result = ($this->handler)($cmd);

        self::assertSame((string) $this->systemId, $result->id);

        $this->em->clear();

        $loaded = $this->em->find(CoatingSystem::class, $this->systemId);
        self::assertNotNull($loaded);
        self::assertSame('Система-CS-Update-НОВЫЙ-'.$suffix, $loaded->getTitle());
        self::assertSame('Описание после.', $loaded->getDescription());
        self::assertSame(Substrate::CONCRETE, $loaded->getSubstrate());
        self::assertSame($treatment->getId(), $loaded->getSurfaceTreatment()->getId());
    }

    public function test_update_replaces_tags(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'Мфр-CS-UpdTags-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Грунт-CS-UpdTags-'.$suffix,
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

        $tagSpec = $container->get(TagSpecification::class);
        $tagA = new Tag('Alpha-'.$suffix, $tagSpec);
        $tagB = new Tag('Beta-'.$suffix, $tagSpec);
        $tagC = new Tag('Gamma-'.$suffix, $tagSpec);
        $this->em->persist($tagA);
        $this->em->persist($tagB);
        $this->em->persist($tagC);
        $this->em->flush();
        $this->coatingId = $coatingId;
        $this->tagIds = [$tagA->getId(), $tagB->getId(), $tagC->getId()];

        // Систему создаём с тегами [A, B]
        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'Система-CS-UpdTags-'.$suffix,
            '',
            Substrate::STEEL_CARBON,
            $treatment,
        );
        $system->appendLayer($coating, 80);
        $system->replaceTags([$tagA, $tagB]);
        $this->repo->save($system);

        // Обновляем метаданные: подаём теги [B, C] — должно перезаписать: A уходит, C приходит.
        $cmd = new UpdateCoatingSystemMetadataCommand(
            id: (string) $this->systemId,
            title: 'Система-CS-UpdTags-'.$suffix,
            description: 'После правки.',
            substrate: Substrate::STEEL_CARBON,
            environment: EnvironmentType::Atmospheric,
            surfaceTreatmentId: $treatment->getId(),
            tagIds: [$tagB->getId(), $tagC->getId()],
        );

        ($this->handler)($cmd);

        $this->em->clear();

        $joinRows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT tag_id FROM coating_system_tag WHERE coating_system_id = ? ORDER BY tag_id',
            [(string) $this->systemId],
        );
        $persistedTagIds = array_column($joinRows, 'tag_id');
        sort($persistedTagIds);
        $expected = [$tagB->getId(), $tagC->getId()];
        sort($expected);
        self::assertSame($expected, $persistedTagIds);
    }

    public function test_update_with_empty_tag_ids_removes_all_tags(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'Мфр-CS-ClrTags-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Грунт-CS-ClrTags-'.$suffix,
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

        $tagSpec = $container->get(TagSpecification::class);
        $tagX = new Tag('Xray-'.$suffix, $tagSpec);
        $this->em->persist($tagX);
        $this->em->flush();
        $this->coatingId = $coatingId;
        $this->tagIds = [$tagX->getId()];

        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'Система-CS-ClrTags-'.$suffix,
            '',
            Substrate::STEEL_CARBON,
            $treatment,
        );
        $system->appendLayer($coating, 80);
        $system->replaceTags([$tagX]);
        $this->repo->save($system);

        $cmd = new UpdateCoatingSystemMetadataCommand(
            id: (string) $this->systemId,
            title: 'Система-CS-ClrTags-'.$suffix,
            description: '',
            substrate: Substrate::STEEL_CARBON,
            environment: EnvironmentType::Atmospheric,
            surfaceTreatmentId: $treatment->getId(),
            tagIds: [],
        );

        ($this->handler)($cmd);

        $this->em->clear();

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT tag_id FROM coating_system_tag WHERE coating_system_id = ?',
            [(string) $this->systemId],
        );
        self::assertSame([], $rows);
    }

    public function test_update_throws_when_system_not_found(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $treatment = $this->createAndPersistTreatment($this->em, $suffix);
        $fakeId = (string) Uuid::v7();

        $cmd = new UpdateCoatingSystemMetadataCommand(
            id: $fakeId,
            title: 'Не важно',
            description: '',
            substrate: Substrate::STEEL_CARBON,
            environment: EnvironmentType::Atmospheric,
            surfaceTreatmentId: $treatment->getId(),
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/не найдена/');

        ($this->handler)($cmd);
    }

    /**
     * Critical: atomic substrate+treatment switch.
     *
     * System starts with STEEL_CARBON + treatment that covers all substrates.
     * We switch to CONCRETE + treatment that covers only CONCRETE.
     * The new treatment is incompatible with the old substrate (STEEL_CARBON),
     * so sequential setSubstrate → setSurfaceTreatment would fail if setSubstrate
     * checked old treatment. setSubstrateAndTreatment must handle both in one step.
     */
    public function test_atomic_substrate_and_treatment_update(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        // Treatment 1: covers all substrates (used for initial state)
        $treatment1 = $this->createAndPersistTreatment($this->em, $suffix);

        // Treatment 2: covers CONCRETE only (incompatible with STEEL_CARBON)
        $this->secondTreatmentId = Uuid::v7();
        $treatment2 = new SurfaceTreatment(
            $this->secondTreatmentId,
            'Только бетон '.$suffix,
            substr('CON-'.$suffix, 0, 30),
            null,
            [Substrate::CONCRETE],
        );
        $this->em->persist($treatment2);
        $this->em->flush();

        $manufacturer = new Manufacturer(
            'Мфр-atomic-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coating = new Coating(
            $coatingId,
            'Грунт-atomic-'.$suffix,
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

        // Create system on STEEL_CARBON with treatment1 (all substrates)
        $this->systemId = Uuid::v7();
        $system = new CoatingSystem(
            $this->systemId,
            'Система-atomic-'.$suffix,
            'До смены.',
            Substrate::STEEL_CARBON,
            $treatment1,
        );
        $system->appendLayer($coating, 80);
        $this->repo->save($system);

        // Switch to CONCRETE + treatment2 (CONCRETE-only).
        // treatment2 is incompatible with old substrate STEEL_CARBON,
        // so sequential setSubstrate/setSurfaceTreatment would throw.
        $cmd = new UpdateCoatingSystemMetadataCommand(
            id: (string) $this->systemId,
            title: 'Система-atomic-'.$suffix,
            description: 'После смены.',
            substrate: Substrate::CONCRETE,
            environment: EnvironmentType::Atmospheric,
            surfaceTreatmentId: $treatment2->getId(),
        );

        $result = ($this->handler)($cmd);

        self::assertSame((string) $this->systemId, $result->id);

        $this->em->clear();

        $loaded = $this->em->find(CoatingSystem::class, $this->systemId);
        self::assertNotNull($loaded);
        self::assertSame(Substrate::CONCRETE, $loaded->getSubstrate());
        self::assertSame($treatment2->getId(), $loaded->getSurfaceTreatment()->getId());
    }
}
