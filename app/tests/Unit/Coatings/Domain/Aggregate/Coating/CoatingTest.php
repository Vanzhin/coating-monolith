<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\Specification\UniqueTitleCoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use PHPUnit\Framework\TestCase;

final class CoatingTest extends TestCase
{
    public function testMinRecoatingForFallsBackToRootDefaultWhenNoBranches(): void
    {
        $globalDefault = new DryingTimeSeries(new TimeAtTemperature(20, 14 * 24 * 60));
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree($globalDefault),
            max: null,
        );

        $series = $coating->minRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP);

        $this->assertSame($globalDefault, $series);
    }

    public function testMinRecoatingForReturnsTopcoatLeafWhenPresent(): void
    {
        $rootDef = new DryingTimeSeries(new TimeAtTemperature(20, 60));
        $atmDef  = new DryingTimeSeries(new TimeAtTemperature(20, 30));
        $epDef   = new DryingTimeSeries(new TimeAtTemperature(20, 15));
        $min = new RecoatingIntervalTree(
            $rootDef,
            'default',
            new RecoatingIntervalTree(
                $atmDef,
                'atmospheric',
                new RecoatingIntervalTree($epDef, 'EP'),
            ),
        );
        $coating = $this->makeCoating(
            min: $min,
            max: null,
        );

        $this->assertSame(
            $epDef,
            $coating->minRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP),
        );
    }

    public function testMinRecoatingForUsesEnvDefaultWhenTopcoatMissing(): void
    {
        $rootDef = new DryingTimeSeries(new TimeAtTemperature(20, 60));
        $atmDef  = new DryingTimeSeries(new TimeAtTemperature(20, 30));
        $min = new RecoatingIntervalTree(
            $rootDef,
            'default',
            new RecoatingIntervalTree($atmDef, 'atmospheric'),
        );
        $coating = $this->makeCoating(
            min: $min,
            max: null,
        );

        $this->assertSame(
            $atmDef,
            $coating->minRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP),
            'EP не задан → возвращаем дефолт среды',
        );
    }

    public function testMinRecoatingPointAtAppliesGetPointToFoundSeries(): void
    {
        $epSeries = new DryingTimeSeries(
            new TimeAtTemperature(20, 30),
            new TimeAtTemperature(30, 15),
        );
        $min = new RecoatingIntervalTree(
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            'default',
            new RecoatingIntervalTree(
                new DryingTimeSeries(new TimeAtTemperature(20, 45)),
                'atmospheric',
                new RecoatingIntervalTree($epSeries, 'EP'),
            ),
        );
        $coating = $this->makeCoating(
            min: $min,
            max: null,
        );

        $point = $coating->minRecoatingPointAt(EnvironmentType::Atmospheric, CoatingBase::EP, 20);
        $this->assertNotNull($point);
        $this->assertSame(30, $point->timeInMinutes);
    }

    public function testMaxRecoatingForReturnsNullWhenMaxIsAbsent(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );

        $this->assertNull(
            $coating->maxRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP),
        );
    }

    public function testMaxRecoatingForUsesEnvDefaultWhenTopcoatMissing(): void
    {
        $rootDef = new DryingTimeSeries(new TimeAtTemperature(20, 14 * 24 * 60));
        $atmDef  = new DryingTimeSeries(new TimeAtTemperature(20, 7  * 24 * 60));
        $max = new RecoatingIntervalTree(
            $rootDef,
            'default',
            new RecoatingIntervalTree($atmDef, 'atmospheric'),
        );
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: $max,
        );

        $series = $coating->maxRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP);

        $this->assertSame($atmDef, $series, 'EP не задан → возвращаем дефолт среды');
    }

    public function testMaxRecoatingForReturnsTopcoatLeafWhenPresent(): void
    {
        $rootDef = new DryingTimeSeries(new TimeAtTemperature(20, 14 * 24 * 60));
        $atmDef  = new DryingTimeSeries(new TimeAtTemperature(20, 7  * 24 * 60));
        $epDef   = new DryingTimeSeries(new TimeAtTemperature(20, 30 * 24 * 60));
        $max = new RecoatingIntervalTree(
            $rootDef,
            'default',
            new RecoatingIntervalTree(
                $atmDef,
                'atmospheric',
                new RecoatingIntervalTree($epDef, 'EP'),
            ),
        );
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: $max,
        );

        $this->assertSame(
            $epDef,
            $coating->maxRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP),
        );
    }

    public function testMaxRecoatingForFallsBackToRootWhenEnvMissing(): void
    {
        $rootDef = new DryingTimeSeries(new TimeAtTemperature(20, 14 * 24 * 60));
        $atmDef  = new DryingTimeSeries(new TimeAtTemperature(20, 7  * 24 * 60));
        $max = new RecoatingIntervalTree(
            $rootDef,
            'default',
            new RecoatingIntervalTree($atmDef, 'atmospheric'),
        );
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: $max,
        );

        $this->assertSame(
            $rootDef,
            $coating->maxRecoatingFor(EnvironmentType::Special, CoatingBase::EP),
            'Special-ветки нет → корневой default',
        );
    }

    public function testMaxRecoatingPointAtAppliesGetPointToFoundSeries(): void
    {
        $epSeries = new DryingTimeSeries(
            new TimeAtTemperature(20, 30 * 24 * 60),
            new TimeAtTemperature(30, 15 * 24 * 60),
        );
        $max = new RecoatingIntervalTree(
            new DryingTimeSeries(new TimeAtTemperature(20, 14 * 24 * 60)),
            'default',
            new RecoatingIntervalTree(
                new DryingTimeSeries(new TimeAtTemperature(20, 7 * 24 * 60)),
                'atmospheric',
                new RecoatingIntervalTree($epSeries, 'EP'),
            ),
        );
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: $max,
        );

        $point = $coating->maxRecoatingPointAt(EnvironmentType::Atmospheric, CoatingBase::EP, 20);
        $this->assertNotNull($point);
        $this->assertSame(30 * 24 * 60, $point->timeInMinutes);
    }

    public function testMaxRecoatingPointAtReturnsNullWhenMaxIsAbsent(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );

        $this->assertNull(
            $coating->maxRecoatingPointAt(EnvironmentType::Atmospheric, CoatingBase::EP, 20),
        );
    }

    private function makeCoating(
        RecoatingIntervalTree $min,
        ?RecoatingIntervalTree $max,
    ): Coating {
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
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(80, 150), 100, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 24 * 60)),
            $min,
            $max,
            1.0,
            null,
            $manufacturer,
            $spec,
        );
    }
}
