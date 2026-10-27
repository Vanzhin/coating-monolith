<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\Service;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Coatings\Application\Service\CoatingSystemsFilterBuilder;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Repository\ThermalEnvironment;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use PHPUnit\Framework\TestCase;

final class CoatingSystemsFilterBuilderTest extends TestCase
{
    public function test_builds_filter_from_primitives(): void
    {
        $filter = CoatingSystemsFilterBuilder::build(new CoatingSystemsFilterInput(
            search: 'эпокси',
            substrates: ['steel_carbon'],
            environment: 'special',
            standard: 'ISO_12944',
            category: 'C5',
            durability: 'HIGH',
            substanceIds: ['11111111-1111-1111-1111-111111111111'],
            applicationMinTempFrom: -10,
            thermalTemperature: 90,
            thermalEnvironment: 'immersion',
            thermalIncludingPeak: true,
            substanceTemperature: 40,
        ));

        self::assertSame([Substrate::STEEL_CARBON], $filter->substrates);
        self::assertSame(EnvironmentType::Special, $filter->environment);
        self::assertSame(ComplianceStandard::ISO_12944, $filter->standard);
        self::assertSame('C5', $filter->category);
        self::assertSame('HIGH', $filter->durability);
        self::assertSame(['11111111-1111-1111-1111-111111111111'], $filter->substanceIds->getList());
        self::assertNotNull($filter->applicationMinTemp);
        self::assertSame(-10, $filter->applicationMinTemp->from);
        self::assertSame(90, $filter->thermalTemperature);
        self::assertSame(ThermalEnvironment::IMMERSION, $filter->thermalEnvironment);
        self::assertTrue($filter->thermalIncludingPeak);
        self::assertInstanceOf(PositiveNumber::class, $filter->substanceTemperature);
    }

    public function test_category_durability_dropped_without_standard(): void
    {
        $filter = CoatingSystemsFilterBuilder::build(new CoatingSystemsFilterInput(
            standard: null,
            category: 'C5',
            durability: 'HIGH',
        ));

        self::assertNull($filter->category);
        self::assertNull($filter->durability);
    }

    public function test_invalid_enum_values_are_dropped(): void
    {
        $filter = CoatingSystemsFilterBuilder::build(new CoatingSystemsFilterInput(
            substrates: ['steel_carbon', 'unobtanium'],
            environment: 'nowhere',
            thermalEnvironment: 'lava',
        ));

        self::assertSame([Substrate::STEEL_CARBON], $filter->substrates);
        self::assertNull($filter->environment);
        self::assertNull($filter->thermalEnvironment);
    }

    public function test_inverted_range_dropped(): void
    {
        $filter = CoatingSystemsFilterBuilder::build(new CoatingSystemsFilterInput(
            applicationMinTempFrom: 100,
            applicationMinTempTo: 10,
        ));

        self::assertNull($filter->applicationMinTemp);
    }

    public function test_non_positive_substance_temperature_is_null(): void
    {
        $filter = CoatingSystemsFilterBuilder::build(new CoatingSystemsFilterInput(substanceTemperature: 0));

        self::assertNull($filter->substanceTemperature);
    }
}
