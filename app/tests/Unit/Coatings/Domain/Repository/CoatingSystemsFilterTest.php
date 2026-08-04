<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\RangeFilter;
use PHPUnit\Framework\TestCase;

final class CoatingSystemsFilterTest extends TestCase
{
    public function test_construction_holds_all_fields(): void
    {
        $filter = new CoatingSystemsFilter(
            search: SearchQuery::tryFromString('эпоксид'),
            substrates: [Substrate::STEEL_CARBON, Substrate::CONCRETE],
            standard: ComplianceStandard::ISO_12944,
            category: 'C4',
            durability: 'HIGH',
            tagIds: new StringCollection('tag-1', 'tag-2'),
            coatingIds: new StringCollection('coating-1', 'coating-2'),
            applicationMinTemp: new RangeFilter(-5, 5),
            minApplicationTimeAt20: new RangeFilter(240, 1440),
            sort: CoatingSystemSort::TITLE_ASC,
            pager: Pager::fromPage(1, 20),
        );

        self::assertSame('эпоксид', $filter->search?->value);
        self::assertSame([Substrate::STEEL_CARBON, Substrate::CONCRETE], $filter->substrates);
        self::assertSame(ComplianceStandard::ISO_12944, $filter->standard);
        self::assertSame('C4', $filter->category);
        self::assertSame('HIGH', $filter->durability);
        self::assertSame(['tag-1', 'tag-2'], $filter->tagIds->getList());
        self::assertSame(['coating-1', 'coating-2'], $filter->coatingIds->getList());
        self::assertNotNull($filter->applicationMinTemp);
        self::assertSame(-5, $filter->applicationMinTemp->from);
        self::assertSame(5, $filter->applicationMinTemp->to);
        self::assertNotNull($filter->minApplicationTimeAt20);
        self::assertSame(240, $filter->minApplicationTimeAt20->from);
        self::assertSame(1440, $filter->minApplicationTimeAt20->to);
        self::assertSame(CoatingSystemSort::TITLE_ASC, $filter->sort);
    }

    public function test_defaults_when_nothing_provided(): void
    {
        $filter = new CoatingSystemsFilter();

        self::assertNull($filter->search);
        self::assertSame([], $filter->substrates);
        self::assertNull($filter->standard);
        self::assertNull($filter->category);
        self::assertNull($filter->durability);
        self::assertSame([], $filter->tagIds->getList());
        self::assertSame([], $filter->coatingIds->getList());
        self::assertNull($filter->applicationMinTemp);
        self::assertNull($filter->minApplicationTimeAt20);
        self::assertSame(CoatingSystemSort::DEFAULT, $filter->sort);
        self::assertSame(1, $filter->pager->page);
        self::assertSame(20, $filter->pager->perPage);
    }
}
