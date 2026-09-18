<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\UseCase\Query;

use App\Coatings\Application\DTO\Coatings\CoatingSuggestDTO;
use App\Coatings\Application\UseCase\Query\AllCoatingsForSuggest\AllCoatingsForSuggestQuery;
use App\Coatings\Application\UseCase\Query\AllCoatingsForSuggest\AllCoatingsForSuggestQueryHandler;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\MixingRatio;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PartsRatio;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AllCoatingsForSuggestQueryHandlerTest extends KernelTestCase
{
    private AllCoatingsForSuggestQueryHandler $handler;
    private EntityManagerInterface $em;
    private CoatingSpecification $coatingSpec;
    private Manufacturer $manufacturer;
    private string $withRatioId;
    private string $noRatioId;
    private string $manufacturerId;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(AllCoatingsForSuggestQueryHandler::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->coatingSpec = $container->get(CoatingSpecification::class);

        $suffix = uniqid('', true);
        $this->manufacturer = new Manufacturer('AllForSuggestQ_'.$suffix, $container->get(ManufacturerSpecification::class));
        $this->em->persist($this->manufacturer);
        $this->manufacturerId = $this->manufacturer->getId();

        $withRatio = $this->makeCoating('WithRatio AllForSuggest '.$suffix);
        $withRatio->setMixingRatio(new MixingRatio(
            byVolume: new PartsRatio(new PositiveNumber(3.0), new PositiveNumber(1.0)),
            byMass: new PartsRatio(new PositiveNumber(100.0), new PositiveNumber(23.0)),
        ));
        $noRatio = $this->makeCoating('NoRatio AllForSuggest '.$suffix);

        $this->em->persist($withRatio);
        $this->em->persist($noRatio);
        $this->em->flush();

        $this->withRatioId = $withRatio->getId();
        $this->noRatioId = $noRatio->getId();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach ([$this->withRatioId, $this->noRatioId] as $id) {
                $c = $em->find(Coating::class, Uuid::fromString($id));
                if (null !== $c) {
                    $em->remove($c);
                }
            }
            $m = $em->find(Manufacturer::class, Uuid::fromString($this->manufacturerId));
            if (null !== $m) {
                $em->remove($m);
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_returns_all_as_suggest_dto_with_mixing_ratio(): void
    {
        $result = ($this->handler)(new AllCoatingsForSuggestQuery());

        self::assertContainsOnlyInstancesOf(CoatingSuggestDTO::class, $result->coatings);

        $byId = [];
        foreach ($result->coatings as $dto) {
            $byId[$dto->id] = $dto;
        }

        self::assertArrayHasKey($this->withRatioId, $byId);
        self::assertArrayHasKey($this->noRatioId, $byId);

        $with = $byId[$this->withRatioId];
        self::assertSame(60, $with->volumeSolid);
        self::assertStringContainsString('(EP, 80–150 мкм)', $with->title);
        self::assertNotNull($with->mixingRatio);
        self::assertEquals([3.0, 1.0], $with->mixingRatio->volume);
        self::assertEquals([100.0, 23.0], $with->mixingRatio->mass);

        self::assertNull($byId[$this->noRatioId]->mixingRatio);
    }

    private function makeCoating(string $title): Coating
    {
        return new Coating(
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
            $this->manufacturer,
            $this->coatingSpec,
        );
    }
}
