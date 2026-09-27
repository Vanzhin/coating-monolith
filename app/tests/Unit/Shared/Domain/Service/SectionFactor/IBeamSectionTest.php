<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Service\SectionFactor;

use App\Shared\Domain\Service\SectionFactor\HeatingScheme;
use App\Shared\Domain\Service\SectionFactor\IBeamSection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class IBeamSectionTest extends TestCase
{
    public function test_area_without_fillet(): void
    {
        // H=200 b=100 s=6 t=8: (200−16)·6 + 2·100·8 = 1104 + 1600 = 2704
        self::assertSame(2704.0, (new IBeamSection(200, 100, 6, 8))->crossSectionArea());
    }

    public function test_area_with_fillet_adds_corner_material(): void
    {
        // + 4·R²·(1−π/4), R=11 → +103.867
        self::assertEqualsWithDelta(2807.87, (new IBeamSection(200, 100, 6, 8, 11))->crossSectionArea(), 0.01);
    }

    public function test_perimeter_four_sided_is_developed_contour(): void
    {
        // 4·b + 2·H − 2·s = 400 + 400 − 12 = 788
        self::assertSame(788.0, (new IBeamSection(200, 100, 6, 8))->heatedPerimeter(HeatingScheme::allSides()));
    }

    public function test_perimeter_three_sided_drops_top_flange_face(): void
    {
        // без верха: bottom(100) + left(294) + right(294) = 688
        self::assertSame(688.0, (new IBeamSection(200, 100, 6, 8))->heatedPerimeter(HeatingScheme::threeSided()));
    }

    public function test_rejects_flange_thickness_that_exceeds_height(): void
    {
        $this->expectException(AppException::class);
        new IBeamSection(15, 100, 6, 8); // 2t=16 > H=15
    }

    public function test_rejects_web_thicker_than_flange_width(): void
    {
        $this->expectException(AppException::class);
        new IBeamSection(200, 5, 6, 8); // s=6 > b=5
    }
}
