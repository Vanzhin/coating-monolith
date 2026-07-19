<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\DTO\Coatings;

use App\Coatings\Application\DTO\Coatings\CoatingDTOTransformer;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalTreeDTO;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\Specification\UniqueTitleCoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use PHPUnit\Framework\TestCase;

final class CoatingDTOTransformerTest extends TestCase
{
    public function test_from_entity_preserves_min_recoating_tree_branches(): void
    {
        $rootDef = new DryingTimeSeries(new TimeAtTemperature(20, 60));
        $atmDef = new DryingTimeSeries(new TimeAtTemperature(20, 30));
        $epDef = new DryingTimeSeries(new TimeAtTemperature(20, 15));

        $minTree = new RecoatingIntervalTree(
            $rootDef,
            'default',
            new RecoatingIntervalTree(
                $atmDef,
                'atmospheric',
                new RecoatingIntervalTree($epDef, 'EP'),
            ),
        );

        $coating = $this->makeCoating(min: $minTree, max: null);
        $dto = (new CoatingDTOTransformer())->fromEntity($coating);

        $this->assertInstanceOf(RecoatingIntervalTreeDTO::class, $dto->minRecoatingInterval);
        $this->assertCount(1, $dto->minRecoatingInterval->default);
        $this->assertSame(60, $dto->minRecoatingInterval->default[0]->time_in_minutes);

        $this->assertArrayHasKey('atmospheric', $dto->minRecoatingInterval->branches);
        $atm = $dto->minRecoatingInterval->branches['atmospheric'];
        $this->assertSame(30, $atm->default[0]->time_in_minutes);

        $this->assertArrayHasKey('ep', $atm->branches);
        $this->assertSame(15, $atm->branches['ep']->default[0]->time_in_minutes);
    }

    public function test_from_entity_returns_null_max_recoating_when_absent(): void
    {
        $minTree = new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60)));
        $coating = $this->makeCoating(min: $minTree, max: null);

        $dto = (new CoatingDTOTransformer())->fromEntity($coating);

        $this->assertNull($dto->maxRecoatingInterval);
    }

    private function makeCoating(
        RecoatingIntervalTree $min,
        ?RecoatingIntervalTree $max,
    ): Coating {
        $manufacturer = $this->createMock(Manufacturer::class);
        $manufacturer->method('getId')->willReturn('00000000-0000-0000-0000-000000000001');
        $manufacturer->method('getTitle')->willReturn('Test');
        $manufacturer->method('getDescription')->willReturn('');

        $spec = new CoatingSpecification($this->createMock(UniqueTitleCoatingSpecification::class));

        return new Coating(
            UuidService::generateUuid(),
            'Test Coating',
            'desc',
            50, 1.2,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(80, 150), 100, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 24 * 60)),
            $min, $max,
            1.0, null,
            $manufacturer, $spec,
        );
    }
}
