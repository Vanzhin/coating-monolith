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
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CoatingSystemChainValidatorTest extends TestCase
{
    public function test_empty_system_is_valid(): void
    {
        $validator = new CoatingSystemChainValidator();
        $sys = $this->newSystem();
        // Не должно бросать исключение
        $validator->validate($sys);
        $this->assertTrue(true);
    }

    public function test_single_layer_is_valid(): void
    {
        $validator = new CoatingSystemChainValidator();
        $sys = $this->newSystem();
        $sys->appendLayer($this->makeCoating(CoatingBase::EP), 100);
        $validator->validate($sys);
        $this->assertTrue(true);
    }

    public function test_compatible_neighbors_is_valid(): void
    {
        // EP поверх EP — допустимо (EP в allowedPrimers EP)
        $validator = new CoatingSystemChainValidator();
        $sys = $this->newSystem();
        $sys->appendLayer($this->makeCoating(CoatingBase::EP), 80);
        $sys->appendLayer($this->makeCoating(CoatingBase::EP), 100);
        $validator->validate($sys);
        $this->assertTrue(true);
    }

    // --- helpers ---

    private function newSystem(): CoatingSystem
    {
        return new CoatingSystem(
            Uuid::v7(),
            'Test System',
            'description',
            Substrate::STEEL_CARBON,
            new SurfaceTreatment(Uuid::v7(), 'Abrasive blast', 'Sa 2.5', null, Substrate::cases()),
            new CoatingSystemChainValidator(),
        );
    }

    private function makeCoating(CoatingBase $base): Coating
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
            new DftRange(new PositiveNumberRange(50, 200), 100, ThicknessType::MIC),
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
}
