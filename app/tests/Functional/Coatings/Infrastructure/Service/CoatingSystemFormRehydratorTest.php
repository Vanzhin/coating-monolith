<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Service;

use App\Coatings\Application\Service\CoatingSystemFormRehydrator;
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
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CoatingSystemFormRehydratorTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private EntityManagerInterface $em;

    private ?Uuid $coatingId = null;
    private ?Uuid $manufacturerId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
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

    public function test_enriches_treatment_and_coating_titles(): void
    {
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $manufacturer = new Manufacturer(
            'Мфр-Rehydrator-'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);
        $this->manufacturerId = Uuid::fromString($manufacturer->getId());

        $coatingId = UuidService::generateUuid();
        $coatingTitle = 'Грунт-Rehydrator-'.$suffix;
        $coating = new Coating(
            $coatingId,
            $coatingTitle,
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

        $rehydrator = new CoatingSystemFormRehydrator(
            $container->get(QueryBusInterface::class),
            $container->get(CoatingRepositoryInterface::class),
        );

        $treatmentId = (string) $treatment->getId();
        $coatingIdString = (string) $coatingId;

        $input = [
            'surfaceTreatmentId' => $treatmentId,
            'layers' => [['coatingId' => $coatingIdString, 'dft' => 60]],
        ];

        $out = $rehydrator->enrichInputDataWithTitles($input);

        self::assertArrayHasKey('surfaceTreatmentTitle', $out);
        self::assertNotSame('', $out['surfaceTreatmentTitle']);
        self::assertArrayHasKey('coatingTitlesById', $out);
        self::assertArrayHasKey($coatingIdString, $out['coatingTitlesById']);
        self::assertStringContainsString($coatingTitle, $out['coatingTitlesById'][$coatingIdString]);
    }

    public function test_no_ids_leaves_input_without_lookups(): void
    {
        $container = static::getContainer();
        $rehydrator = new CoatingSystemFormRehydrator(
            $container->get(QueryBusInterface::class),
            $container->get(CoatingRepositoryInterface::class),
        );

        $out = $rehydrator->enrichInputDataWithTitles(['layers' => []]);

        self::assertSame([], $out['coatingTitlesById']);
    }
}
