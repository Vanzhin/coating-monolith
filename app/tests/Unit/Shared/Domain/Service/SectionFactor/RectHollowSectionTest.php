<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Service\SectionFactor;

use App\Shared\Domain\Service\SectionFactor\HeatingScheme;
use App\Shared\Domain\Service\SectionFactor\RectHollowSection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class RectHollowSectionTest extends TestCase
{
    public function test_area_no_fillet(): void
    {
        // квадрат 100×100×5: 2·5·(100+100−10) = 10·190 = 1900
        self::assertEqualsWithDelta(1900.0, (new RectHollowSection(100, 100, 5))->crossSectionArea(), 0.001);
    }

    public function test_perimeter_four_sided_is_outer(): void
    {
        // 2·(H+W) = 400
        self::assertSame(400.0, (new RectHollowSection(100, 100, 5))->heatedPerimeter(HeatingScheme::allSides()));
    }

    public function test_perimeter_three_sided_drops_top(): void
    {
        // без верха: bottom(100)+left(100)+right(100) = 300
        self::assertSame(300.0, (new RectHollowSection(100, 100, 5))->heatedPerimeter(HeatingScheme::threeSided()));
    }

    public function test_perimeter_subtracts_corner_radius(): void
    {
        // ГОСТ 30245 100×100×5 R10: 2(H+W) минус 4 скругления 4·R·(2−π/2) → 382.832 (как эталон)
        $profile = new RectHollowSection(100, 100, 5, 10);
        self::assertEqualsWithDelta(382.832, $profile->heatedPerimeter(HeatingScheme::allSides()), 0.01);
    }

    public function test_rejects_wall_too_thick(): void
    {
        $this->expectException(AppException::class);
        new RectHollowSection(100, 100, 60);
    }
}
