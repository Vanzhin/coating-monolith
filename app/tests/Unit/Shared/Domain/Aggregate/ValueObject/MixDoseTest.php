<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Domain\Aggregate\ValueObject\MixDose;
use PHPUnit\Framework\TestCase;

final class MixDoseTest extends TestCase
{
    public function test_exposes_base_additions_and_total(): void
    {
        $dose = new MixDose(30.0, 10.0);

        self::assertSame([30.0, 10.0], $dose->getAmounts());
        self::assertSame(30.0, $dose->getBase());
        self::assertSame([10.0], $dose->getAdditions());
        self::assertEqualsWithDelta(40.0, $dose->getTotal(), 1e-9);
    }

    public function test_supports_more_than_one_addition(): void
    {
        $dose = new MixDose(4.0, 1.0, 0.5);

        self::assertSame(4.0, $dose->getBase());
        self::assertSame([1.0, 0.5], $dose->getAdditions());
        self::assertEqualsWithDelta(5.5, $dose->getTotal(), 1e-9);
    }
}
