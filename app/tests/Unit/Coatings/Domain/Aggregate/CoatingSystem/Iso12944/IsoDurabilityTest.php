<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\CoatingSystem\Iso12944;

use App\Coatings\Domain\Aggregate\CoatingSystem\Iso12944\IsoDurability;
use PHPUnit\Framework\TestCase;

final class IsoDurabilityTest extends TestCase
{
    public function test_at_or_above_returns_self_and_greater(): void
    {
        self::assertSame(['LOW', 'MEDIUM', 'HIGH', 'VERY_HIGH'], IsoDurability::LOW->atOrAbove());
        self::assertSame(['MEDIUM', 'HIGH', 'VERY_HIGH'], IsoDurability::MEDIUM->atOrAbove());
        self::assertSame(['HIGH', 'VERY_HIGH'], IsoDurability::HIGH->atOrAbove());
        self::assertSame(['VERY_HIGH'], IsoDurability::VERY_HIGH->atOrAbove());
    }
}
