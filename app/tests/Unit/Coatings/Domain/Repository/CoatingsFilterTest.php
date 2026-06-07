<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Repository;

use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

class CoatingsFilterTest extends TestCase
{
    private const VALID_UUID_A = '11111111-1111-4111-8111-111111111111';

    public function testDefaultsAreEmptyAndNull(): void
    {
        $filter = new CoatingsFilter();
        $this->assertNull($filter->search);
        $this->assertSame([], $filter->manufacturerIds->getList());
        $this->assertNull($filter->pager);
    }

    public function testSearchShortThrows(): void
    {
        $this->expectException(AppException::class);
        new CoatingsFilter(search: 'ва');
    }

    public function testValidSearchAndFacetTogether(): void
    {
        $filter = new CoatingsFilter(
            search: 'эпоксидная',
            manufacturerIds: new StringCollection(self::VALID_UUID_A),
        );
        $this->assertSame('эпоксидная', $filter->search);
        $this->assertSame([self::VALID_UUID_A], $filter->manufacturerIds->getList());
    }
}
