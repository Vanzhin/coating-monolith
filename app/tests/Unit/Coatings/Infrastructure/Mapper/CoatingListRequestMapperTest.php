<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Mapper;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Repository\CoatingSort;
use App\Coatings\Infrastructure\Mapper\CoatingListRequestMapper;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Helper\QueryParams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CoatingListRequestMapperTest extends TestCase
{
    private CoatingListRequestMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new CoatingListRequestMapper(new QueryParams());
    }

    public function test_empty_request_gives_empty_filter(): void
    {
        $filter = $this->mapper->filterFromRequest(Request::create('/', 'GET'));

        self::assertNull($filter->search);
        self::assertSame([], $filter->manufacturerIds->getList());
        self::assertSame([], $filter->baseValues->getList());
        self::assertNull($filter->minRecoating20);
        self::assertSame(CoatingSort::DEFAULT, $filter->sort);
    }

    public function test_base_values_are_enum_filtered_and_deduped(): void
    {
        $valid = CoatingBase::cases()[0]->value;
        $request = Request::create('/', 'GET', [
            'baseValues' => [$valid, 'GARBAGE', $valid],
        ]);

        $filter = $this->mapper->filterFromRequest($request);

        self::assertSame([$valid], $filter->baseValues->getList());
    }

    public function test_recoat_units_are_converted_to_minutes(): void
    {
        $request = Request::create('/', 'GET', [
            'minRecoat20From' => '2',   // часы → 120 минут
            'maxRecoat20To' => '3',     // дни → 4320 минут
        ]);

        $filter = $this->mapper->filterFromRequest($request);

        self::assertNotNull($filter->minRecoating20);
        self::assertSame(120, $filter->minRecoating20->from);
        self::assertNotNull($filter->maxRecoating20);
        self::assertSame(4320, $filter->maxRecoating20->to);
    }

    public function test_inverted_range_throws(): void
    {
        $request = Request::create('/', 'GET', [
            'appMinTempFrom' => '10',
            'appMinTempTo' => '5',
        ]);

        $this->expectException(AppException::class);
        $this->mapper->filterFromRequest($request);
    }

    public function test_unknown_sort_falls_back_to_default(): void
    {
        $request = Request::create('/', 'GET', ['sort' => 'nonsense']);

        self::assertSame(CoatingSort::DEFAULT, $this->mapper->filterFromRequest($request)->sort);
    }
}
