<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Compliance\Iso12944;

use App\Coatings\Domain\Compliance\Iso12944\IsoCorrosivityCategory;
use PHPUnit\Framework\TestCase;

final class IsoCorrosivityCategoryTest extends TestCase
{
    public function test_at_or_above_in_family_returns_atmospheric_range(): void
    {
        self::assertSame(['C3', 'C4', 'C5', 'CX'], IsoCorrosivityCategory::C3->atOrAboveInFamily());
        self::assertSame(['CX'], IsoCorrosivityCategory::CX->atOrAboveInFamily());
    }

    public function test_at_or_above_in_family_returns_immersion_range(): void
    {
        self::assertSame(['Im1', 'Im2', 'Im3'], IsoCorrosivityCategory::IM1->atOrAboveInFamily());
        self::assertSame(['Im3'], IsoCorrosivityCategory::IM3->atOrAboveInFamily());
    }
}
