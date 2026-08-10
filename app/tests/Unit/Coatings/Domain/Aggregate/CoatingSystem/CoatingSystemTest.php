<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\Specification\UniqueTitleCoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Coatings\Domain\Event\CoatingSystemMutated;
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
        $akCoating = $this->newCoatingWithBase(CoatingBase::AK);
        $esiCoating = $this->newCoatingWithBase(CoatingBase::ESI);

        $sys->appendLayer($akCoating, 60); // должен пройти
        $this->expectException(AppException::class);
        $sys->appendLayer($esiCoating, 60); // должен кинуть
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

    public function test_first_layer_after_removing_first_returns_next(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $l2 = $sys->appendLayer($this->newCoatingCompatibleAll(), 100);
        $sys->removeLayerAt(1);
        self::assertSame($l2, $sys->firstLayer());
    }

    public function test_replace_layers_replaces_composition_and_renumbers(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 100);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 40);

        $newA = $this->newCoatingCompatibleAll();
        $newB = $this->newCoatingCompatibleAll();
        $sys->replaceLayers([
            ['coating' => $newA, 'dft' => 55],
            ['coating' => $newB, 'dft' => 90],
        ]);

        self::assertSame(2, $sys->layerCount());
        self::assertSame([1, 2], $this->positions($sys));
        self::assertSame(145, $sys->totalDft());
    }

    public function test_replace_layers_with_empty_list_clears_system(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $sys->replaceLayers([]);
        self::assertSame(0, $sys->layerCount());
    }

    public function test_append_sixth_layer_throws(): void
    {
        $sys = $this->newSystem();
        for ($i = 0; $i < 5; ++$i) {
            $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        }
        $this->expectException(AppException::class);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
    }

    public function test_insert_sixth_layer_throws(): void
    {
        $sys = $this->newSystem();
        for ($i = 0; $i < 5; ++$i) {
            $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        }
        $this->expectException(AppException::class);
        $sys->insertLayerAt(3, $this->newCoatingCompatibleAll(), 60);
    }

    public function test_replace_with_six_layers_throws(): void
    {
        $sys = $this->newSystem();
        $items = [];
        for ($i = 0; $i < 6; ++$i) {
            $items[] = ['coating' => $this->newCoatingCompatibleAll(), 'dft' => 60];
        }
        $this->expectException(AppException::class);
        $sys->replaceLayers($items);
    }

    public function test_replace_layers_rejects_incompatible_chain(): void
    {
        $sys = $this->newSystem();
        $ak = $this->newCoatingWithBase(CoatingBase::AK);
        $esi = $this->newCoatingWithBase(CoatingBase::ESI);

        $this->expectException(AppException::class);
        $sys->replaceLayers([
            ['coating' => $ak, 'dft' => 60],
            ['coating' => $esi, 'dft' => 60],
        ]);
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
        // Sleep 2 ms to ensure timestamp strictly advances
        usleep(2000);
        $sys->appendLayer($this->newCoatingCompatibleAll(), 60);
        $after = $sys->getUpdatedAt();
        self::assertNotSame($before, $after);
        self::assertGreaterThan($before, $after);
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

    public function test_set_surface_treatment_throws_when_substrate_not_in_scope(): void
    {
        // treatment scope = CONCRETE only, but system substrate = STEEL_CARBON
        $treatment = $this->newTreatment([Substrate::CONCRETE]);
        $this->expectException(AppException::class);
        new CoatingSystem(
            Uuid::v7(),
            'Test',
            '',
            Substrate::STEEL_CARBON,
            $treatment,
        );
    }

    public function test_tags_are_empty_after_construction(): void
    {
        $sys = $this->newSystem();
        self::assertCount(0, $sys->getTags());
    }

    public function test_add_tag_puts_it_into_collection_and_touches(): void
    {
        $sys = $this->newSystem();
        $initialUpdatedAt = $sys->getUpdatedAt();
        // Ensure a measurable tick.
        usleep(1000);
        $tag = $this->createStub(Tag::class);

        $sys->addTag($tag);

        self::assertCount(1, $sys->getTags());
        self::assertTrue($sys->getTags()->contains($tag));
        self::assertGreaterThan($initialUpdatedAt, $sys->getUpdatedAt());
    }

    public function test_add_tag_is_idempotent(): void
    {
        $sys = $this->newSystem();
        $tag = $this->createStub(Tag::class);
        $sys->addTag($tag);
        $sys->addTag($tag);
        self::assertCount(1, $sys->getTags());
    }

    public function test_remove_tag_takes_it_out(): void
    {
        $sys = $this->newSystem();
        $tag = $this->createStub(Tag::class);
        $sys->addTag($tag);
        $sys->removeTag($tag);
        self::assertCount(0, $sys->getTags());
    }

    public function test_replace_tags_swaps_full_set(): void
    {
        $sys = $this->newSystem();
        $t1 = $this->createStub(Tag::class);
        $t2 = $this->createStub(Tag::class);
        $t3 = $this->createStub(Tag::class);
        $sys->addTag($t1);
        $sys->addTag($t2);

        $sys->replaceTags([$t2, $t3]);

        self::assertCount(2, $sys->getTags());
        self::assertTrue($sys->getTags()->contains($t2));
        self::assertTrue($sys->getTags()->contains($t3));
        self::assertFalse($sys->getTags()->contains($t1));
    }

    public function test_set_substrate_throws_when_treatment_no_longer_matches(): void
    {
        // treatment scope = STEEL_CARBON only, system substrate = STEEL_CARBON
        $treatment = $this->newTreatment([Substrate::STEEL_CARBON]);
        $system = new CoatingSystem(
            Uuid::v7(),
            'Test',
            '',
            Substrate::STEEL_CARBON,
            $treatment,
        );

        // now change substrate to CONCRETE → treatment scope does not include CONCRETE
        $this->expectException(AppException::class);
        $system->setSubstrate(Substrate::CONCRETE);
    }

    public function test_min_building_time_sums_interpolated_intervals_except_top(): void
    {
        $sys = $this->newSystem();
        $coatingA = $this->makeCoating(sourceMinutes: 240, tdsDft: 100);
        $coatingB = $this->makeCoating(applicationMinTemp: 10);
        $sys->appendLayer($coatingA, 80);   // 240*80/100 = 192
        $sys->appendLayer($coatingB, 80);   // top, не участвует
        self::assertSame(192, $sys->minApplicationTimeAt20Minutes());
        self::assertSame(10, $sys->maxLayerApplicationMinTemp());
    }

    public function test_min_building_time_uses_topcoat_specific_branch(): void
    {
        // Нижнее покрытие: под atmospheric интервал зависит от основания топкоата — EP=60, PUR=180.
        $branchedMin = new RecoatingIntervalTree(
            new DryingTimeSeries(new TimeAtTemperature(20, 300)),
            'default',
            new RecoatingIntervalTree(
                new DryingTimeSeries(new TimeAtTemperature(20, 300)),
                'atmospheric',
                new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60)), 'EP'),
                new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 180)), 'PUR'),
            ),
        );
        // tdsDft = толщина слоя = 100 → пересчёт по толщине identity.
        $under = $this->makeCoating(CoatingBase::EP, 40, 500, tdsDft: 100, minTree: $branchedMin);

        $sysEp = $this->newSystem();
        $sysEp->appendLayer($under, 100);
        $sysEp->appendLayer($this->makeCoating(CoatingBase::EP), 100); // топкоат EP
        self::assertSame(60, $sysEp->minApplicationTimeAt20Minutes());

        $sysPur = $this->newSystem();
        $sysPur->appendLayer($under, 100);
        $sysPur->appendLayer($this->makeCoating(CoatingBase::PUR), 100); // топкоат PUR
        self::assertSame(180, $sysPur->minApplicationTimeAt20Minutes());
    }

    public function test_min_building_time_uses_system_environment_for_lookup(): void
    {
        // Ветка есть только под atmospheric. Система в среде Immersion → корневой default (300).
        $branchedMin = new RecoatingIntervalTree(
            new DryingTimeSeries(new TimeAtTemperature(20, 300)),
            'default',
            new RecoatingIntervalTree(
                new DryingTimeSeries(new TimeAtTemperature(20, 300)),
                'atmospheric',
                new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60)), 'EP'),
            ),
        );
        $under = $this->makeCoating(CoatingBase::EP, 40, 500, tdsDft: 100, minTree: $branchedMin);

        $sys = $this->newSystem(environment: EnvironmentType::Immersion);
        $sys->appendLayer($under, 100);
        $sys->appendLayer($this->makeCoating(CoatingBase::EP), 100);
        self::assertSame(300, $sys->minApplicationTimeAt20Minutes());
    }

    public function test_mutation_raises_coating_system_mutated_event(): void
    {
        $sys = $this->newSystem();
        $sys->appendLayer($this->newCoating(), 80);
        $events = $sys->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CoatingSystemMutated::class, $events[0]);
        self::assertSame($sys->getId(), $events[0]->systemId);
    }

    public function test_asserts_layers_are_chainable_via_can_becovered_by(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/несовместим/');
        // ESI поверх FEVE не совместимо (по CoatingBase::allowedPrimers)
        $sys = $this->newSystem();
        $sys->appendLayer($this->makeCoating(CoatingBase::FEVE), 80);
        $sys->appendLayer($this->makeCoating(CoatingBase::ESI), 80);
    }

    // --- helpers ---

    private function newSystem(
        string $title = 'Test System',
        EnvironmentType $environment = EnvironmentType::Atmospheric,
    ): CoatingSystem {
        return new CoatingSystem(
            Uuid::v7(),
            $title,
            'description',
            Substrate::STEEL_CARBON,
            $this->newTreatment([Substrate::STEEL_CARBON]),
            $environment,
        );
    }

    /**
     * @param list<Substrate> $scope
     */
    private function newTreatment(array $scope = []): SurfaceTreatment
    {
        if ([] === $scope) {
            $scope = Substrate::cases();
        }

        return new SurfaceTreatment(
            Uuid::v7(),
            'Абразивоструйная очистка',
            'Sa 2½',
            'ISO 8501-1',
            $scope,
        );
    }

    /**
     * Покрытие EP с широким dft-диапазоном (50–500 мкм) и совместимое само с собой.
     */
    private function newCoating(): Coating
    {
        return $this->makeCoating();
    }

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

    private function makeCoating(
        CoatingBase $base = CoatingBase::EP,
        int $dftMin = 40,
        int $dftMax = 500,
        int $applicationMinTemp = 5,
        int $tdsDft = 0,
        int $sourceMinutes = 60,
        ?RecoatingIntervalTree $minTree = null,
    ): Coating {
        $manufacturer = $this->createMock(Manufacturer::class);
        $manufacturer->method('getId')->willReturn('00000000-0000-0000-0000-000000000001');

        $spec = new CoatingSpecification(
            $this->createMock(UniqueTitleCoatingSpecification::class),
        );

        $resolvedTdsDft = $tdsDft > 0 ? $tdsDft : (int) (($dftMin + $dftMax) / 2);

        return new Coating(
            UuidService::generateUuid(),
            'Test Coating',
            'desc',
            50,
            1.2,
            $base,
            new DftRange(new PositiveNumberRange($dftMin, $dftMax), $resolvedTdsDft, ThicknessType::MIC),
            $applicationMinTemp,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 24 * 60)),
            $minTree ?? new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, $sourceMinutes))),
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
