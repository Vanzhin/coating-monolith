<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Mapper;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Coatings\Infrastructure\Mapper\CoatingSystemListRequestMapper;
use App\Shared\Infrastructure\Helper\QueryParams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CoatingSystemListRequestMapperTest extends TestCase
{
    private CoatingSystemListRequestMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new CoatingSystemListRequestMapper(new QueryParams());
    }

    public function test_empty_request_gives_defaults(): void
    {
        $filter = $this->mapper->filterFromRequest(Request::create('/', 'GET'));

        self::assertNull($filter->search);
        self::assertSame([], $filter->substrates);
        self::assertNull($filter->standard);
        self::assertNull($filter->category);
        self::assertSame(CoatingSystemSort::DEFAULT, $filter->sort);
    }

    public function test_substrates_are_enum_filtered(): void
    {
        $valid = Substrate::cases()[0]->value;
        $request = Request::create('/', 'GET', ['substrates' => [$valid, 'GARBAGE']]);

        $filter = $this->mapper->filterFromRequest($request);

        self::assertSame([Substrate::from($valid)], $filter->substrates);
    }

    public function test_category_ignored_without_standard(): void
    {
        $request = Request::create('/', 'GET', ['category' => 'C3', 'durability' => 'H']);

        $filter = $this->mapper->filterFromRequest($request);

        self::assertNull($filter->category);
        self::assertNull($filter->durability);
    }

    public function test_min_application_time_converted_to_minutes(): void
    {
        $request = Request::create('/', 'GET', [
            'minApplicationTimeAt20From' => '2', // часы → 120 минут
        ]);

        $filter = $this->mapper->filterFromRequest($request);

        self::assertNotNull($filter->minApplicationTimeAt20);
        self::assertSame(120, $filter->minApplicationTimeAt20->from);
    }

    public function test_inverted_range_is_dropped_to_null(): void
    {
        $request = Request::create('/', 'GET', [
            'applicationMinTempFrom' => '10',
            'applicationMinTempTo' => '5',
        ]);

        self::assertNull($this->mapper->filterFromRequest($request)->applicationMinTemp);
    }

    public function test_coating_ids_filtered_by_uuid(): void
    {
        // Symfony Uuid::isValid() требует RFC4122-вариант в 4-й группе ([89ab]xxx),
        // поэтому '1111-1111' из чернового фикстур-примера не проходит — берём валидный UUID.
        $request = Request::create('/', 'GET', [
            'coatingIds' => ['11111111-1111-1111-8111-111111111111', 'not-a-uuid'],
        ]);

        $filter = $this->mapper->filterFromRequest($request);

        self::assertSame(['11111111-1111-1111-8111-111111111111'], $filter->coatingIds->getList());
    }
}
