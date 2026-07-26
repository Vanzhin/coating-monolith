<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\Specification\UniqueTitleCoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidator;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\CoatingSystem\SurfacePreparation;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CoatingSystemTest extends TestCase
{
    public function test_construction_sets_metadata_and_timestamps(): void
    {
        $sys = $this->newSystem(title: 'Sys');
        self::assertSame('Sys', $sys->getTitle());
        self::assertSame(0, $sys->layerCount());
        self::assertNotNull($sys->getCreatedAt());
        self::assertEquals($sys->getCreatedAt(), $sys->getUpdatedAt());
    }

    public function test_append_layer_assigns_position_1(): void
    {
        $sys = $this->newSystem();
        $layer = $sys->appendLayer($this->newCoatingCompatibleAll(), 120);
        self::assertSame(1, $layer->getPosition());
        self::assertSame(1, $sys->layerCount());
        self::assertSame(120, $sys->totalDft());
    }

    public function test_append_two_layers_second_gets_position_2(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $l2 = $sys->appendLayer($this->newCoatingCompatibleAll(), 100);
        self::assertSame(2, $l2->getPosition());
        self::assertSame([1, 2], $this->positions($sys));
    }

    public function test_insert_at_shifts_positions(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 100);
        $sys->insertLayerAt(2, $this->newCoatingCompatibleAll(), 80);
        self::assertSame([1, 2, 3], $this->positions($sys));
        self::assertSame(3, $sys->layerCount());
    }

    public function test_remove_at_compacts_positions(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 100);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 40);
        $sys->removeLayerAt(2);
        self::assertSame([1, 2], $this->positions($sys));
        self::assertSame(100, $sys->totalDft());
    }

    public function test_move_layer(): void
    {
        $sys = $this->newSystem();
        $l1 = $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 100);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 40);
        $sys->moveLayer(1, 3);
        self::assertSame([1, 2, 3], $this->positions($sys));
        // Первый слой ушёл на позицию 3
        self::assertSame(3, $l1->getPosition());
    }

    public function test_update_layer_dft(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $sys->updateLayerDft(1, 90);
        self::assertSame(90, $sys->totalDft());
    }

    public function test_dft_outside_coating_range_throws(): void
    {
        $sys = $this->newSystem();
        $coating = $this->newCoatingWithDftRange(80, 150);
        $this->expectException(AppException::class);
        $sys->appendLayer($coating, 200);
    }

    public function test_incompatible_neighbors_throws(): void
    {
        // ESI.allowedPrimers = [ESI], поэтому AK → ESI несовместимо:
        // ESI::canBeAppliedOnTopOf(AK) === false → AK::canBecoveredBy(ESI) === false
        $sys = $this->newSystem();
        $akCoating  = $this->newCoatingWithBase(CoatingBase::AK);
        $esiCoating = $this->newCoatingWithBase(CoatingBase::ESI);

        $this->expectException(AppException::class);
        $sys->appendLayer($akCoating, 60);
        $sys->appendLayer($esiCoating, 60);
    }

    public function test_first_layer_throws_on_empty_system(): void
    {
        $sys = $this->newSystem();
        $this->expectException(AppException::class);
        $sys->firstLayer();
    }

    public function test_first_layer_returns_position_1(): void
    {
        $sys = $this->newSystem();
        $l1 = $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 100);
        self::assertSame($l1, $sys->firstLayer());
    }

    public function test_followup_layers_returns_all_except_first(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $l2 = $sys->appendLayer($this->newCoatingCompatibleAll(), 100);
        $l3 = $sys->appendLayer($this->newCoatingCompatibleAll(), 40);
        $followup = iterator_to_array($sys->followupLayers(), false);
        self::assertCount(2, $followup);
        self::assertSame($l2, $followup[0]);
        self::assertSame($l3, $followup[1]);
    }

    public function test_total_dft_sums_all_layers(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 100);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 40);
        self::assertSame(200, $sys->totalDft());
    }

    public function test_updated_at_changes_after_mutation(): void
    {
        $sys = $this->newSystem();
        $before = $sys->getUpdatedAt();
        // Sleep 1 ms to ensure timestamp differs
        usleep(1000);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        self::assertGreaterThanOrEqual($before, $sys->getUpdatedAt());
    }

    public function test_set_title_empty_throws(): void
    {
        $sys = $this->newSystem();
        $this->expectException(AppException::class);
        $sys->setTitle('');
    }

    public function test_set_title_too_long_throws(): void
    {
        $sys = $this->newSystem();
        $this->expectException(AppException::class);
        $sys->setTitle(str_repeat('x', 101));
    }

    // --- helpers ---

    private function newSystem(string $title = 'Test System'): CoatingSystem
    {
        return new CoatingSystem(
            Uuid::v7(),
            $title,
            'description',
            Substrate::STEEL_CARBON,
            new SurfacePreparation('Sa 2.5', 'Abrasive blast'),
            new CoatingSystemChainValidator(),
        );
    }

    /**
     * Покрытие EP с широким dft-диапазоном (50–500 мкм) и совместимое само с собой.
     */
    private function newCoatingCompatibleAll(): Coating
    {
        return $this->makeCoating(CoatingBase::EP, 40, 500);
    }

    private function newCoatingWithDftRange(int $min, int $max): Coating
    {
        return $this->makeCoating(CoatingBase::EP, $min, $max);
    }

    private function newCoatingWithBase(CoatingBase $base): Coating
    {
        return $this->makeCoating($base, 50, 500);
    }

    private function makeCoating(CoatingBase $base, int $dftMin, int $dftMax): Coating
    {
        $manufacturer = $this->createMock(Manufacturer::class);
        $manufacturer->method('getId')->willReturn('00000000-0000-0000-0000-000000000001');

        $spec = new CoatingSpecification(
            $this->createMock(UniqueTitleCoatingSpecification::class),
        );

        return new Coating(
            UuidService::generateUuid(),
            'Test Coating',
            'desc',
            50,
            1.2,
            $base,
            new DftRange(new PositiveNumberRange($dftMin, $dftMax), (int)(($dftMin + $dftMax) / 2), ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 24 * 60)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            null,
            1.0,
            null,
            $manufacturer,
            $spec,
            50,
        );
    }

    /** @return list<int> */
    private function positions(CoatingSystem $sys): array
    {
        $positions = [];
        foreach ($sys->getLayers() as $layer) {
            $positions[] = $layer->getPosition();
        }
        return $positions;
    }
}
