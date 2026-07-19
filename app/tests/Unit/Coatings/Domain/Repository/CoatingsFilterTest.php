<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Repository;

use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Coatings\Domain\Repository\ThermalEnvironment;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

class CoatingsFilterTest extends TestCase
{
    private const VALID_UUID_A = '11111111-1111-4111-8111-111111111111';

    public function test_defaults_are_empty_and_null(): void
    {
        $filter = new CoatingsFilter();
        $this->assertNull($filter->search);
        $this->assertSame([], $filter->manufacturerIds->getList());
        $this->assertNull($filter->pager);
        $this->assertNull($filter->thermalTemperature);
        $this->assertNull($filter->thermalEnvironment);
        $this->assertFalse($filter->thermalIncludingPeak);
        $this->assertFalse($filter->hasThermalFacet());
        $this->assertSame([], $filter->baseValues->getList());
    }

    public function test_valid_search_and_facet_together(): void
    {
        $filter = new CoatingsFilter(
            search: SearchQuery::tryFromString('эпоксидная'),
            manufacturerIds: new StringCollection(self::VALID_UUID_A),
        );
        $this->assertNotNull($filter->search);
        $this->assertSame('эпоксидная', $filter->search->value);
        $this->assertSame([self::VALID_UUID_A], $filter->manufacturerIds->getList());
    }

    public function test_has_thermal_facet_requires_both_temperature_and_environment(): void
    {
        $onlyTemp = new CoatingsFilter(thermalTemperature: 90);
        $this->assertFalse($onlyTemp->hasThermalFacet());

        $onlyEnv = new CoatingsFilter(thermalEnvironment: ThermalEnvironment::DRY_HEAT);
        $this->assertFalse($onlyEnv->hasThermalFacet());

        $both = new CoatingsFilter(
            thermalTemperature: 90,
            thermalEnvironment: ThermalEnvironment::DRY_HEAT,
        );
        $this->assertTrue($both->hasThermalFacet());
    }

    public function test_rejects_out_of_range_thermal_temperature(): void
    {
        $this->expectException(AppException::class);
        new CoatingsFilter(thermalTemperature: 9999);
    }
}
