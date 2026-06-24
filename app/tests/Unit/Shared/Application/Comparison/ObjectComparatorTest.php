<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Comparison;

use App\Shared\Application\Comparison\ComparisonConfig;
use App\Shared\Application\Comparison\ObjectComparator;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

final class ObjectComparatorTest extends TestCase
{
    private function comparator(): ObjectComparator
    {
        return new ObjectComparator(PropertyAccess::createPropertyAccessor());
    }

    public function testThrowsWhenFewerThanTwoObjects(): void
    {
        $this->expectException(AppException::class);
        $this->comparator()->compare(new ComparisonConfig(['x']), new \stdClass());
    }

    public function testThrowsWhenObjectsAreDifferentClasses(): void
    {
        $a = new class { public int $x = 1; };
        $b = new class { public int $x = 1; };
        $this->expectException(AppException::class);
        $this->comparator()->compare(new ComparisonConfig(['x']), $a, $b);
    }

    public function testScalarFieldsEqualMarkedNotDifferent(): void
    {
        $a = new class { public int $x = 1; public string $s = 'a'; };
        $b = clone $a;
        $result = $this->comparator()->compare(new ComparisonConfig(['x', 's']), $a, $b);
        $this->assertCount(2, $result->rows);
        $this->assertFalse($result->rows[0]->isDifferent);
        $this->assertFalse($result->rows[1]->isDifferent);
        $this->assertSame([1, 1], $result->rows[0]->values);
    }

    public function testScalarFieldsDifferMarkedDifferent(): void
    {
        $make = fn(int $v) => new class($v) { public function __construct(public int $x) {} };
        $a = $make(1);
        $b = $make(2);
        $result = $this->comparator()->compare(new ComparisonConfig(['x']), $a, $b);
        $this->assertTrue($result->rows[0]->isDifferent);
        $this->assertSame([1, 2], $result->rows[0]->values);
    }

    public function testStructurallyEqualValueObjectsMarkedNotDifferent(): void
    {
        $vo1 = new readonly class(10, 20) { public function __construct(public int $min, public int $max) {} };
        $vo2 = new $vo1(10, 20);
        $owner1 = new class($vo1) { public function __construct(public object $range) {} };
        $owner2 = new $owner1($vo2);
        $result = $this->comparator()->compare(new ComparisonConfig(['range']), $owner1, $owner2);
        $this->assertFalse($result->rows[0]->isDifferent, 'SORT_REGULAR should deep-compare VO by props');
    }

    public function testNestedPropertyPath(): void
    {
        $makeInner = fn() => new class { public int $tds = 100; };
        $inner1 = $makeInner();
        $inner2 = new $inner1();
        $makeOuter = fn(object $dft) => new class($dft) { public function __construct(public object $dft) {} };
        $a = $makeOuter($inner1);
        $b = new $a($inner2);
        $result = $this->comparator()->compare(new ComparisonConfig(['dft.tds']), $a, $b);
        $this->assertSame('dft.tds', $result->rows[0]->field);
        $this->assertSame([100, 100], $result->rows[0]->values);
        $this->assertFalse($result->rows[0]->isDifferent);
    }

    public function testThreeObjectsAllEqual(): void
    {
        $make = fn(int $v) => new class($v) { public function __construct(public int $x) {} };
        $result = $this->comparator()->compare(new ComparisonConfig(['x']), $make(5), $make(5), $make(5));
        $this->assertFalse($result->rows[0]->isDifferent);
    }

    public function testThreeObjectsOneDiffers(): void
    {
        $make = fn(int $v) => new class($v) { public function __construct(public int $x) {} };
        $result = $this->comparator()->compare(new ComparisonConfig(['x']), $make(5), $make(5), $make(7));
        $this->assertTrue($result->rows[0]->isDifferent);
    }
}
