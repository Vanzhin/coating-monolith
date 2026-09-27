<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Service\SectionFactor;

use App\Shared\Domain\Service\SectionFactor\AngleSection;
use App\Shared\Domain\Service\SectionFactor\HeatingScheme;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class AngleSectionTest extends TestCase
{
    public function test_area_no_fillet(): void
    {
        // равнополочный 100×100×8: (100+100−8)·8 = 1536
        self::assertEqualsWithDelta(1536.0, (new AngleSection(100, 100, 8))->crossSectionArea(), 0.001);
    }

    public function test_perimeter_both_faces_exposed(): void
    {
        // база (b1+b2)=200 + верх(b2=100) + лево(b1=100) = 400
        self::assertEqualsWithDelta(400.0, (new AngleSection(100, 100, 8))->heatedPerimeter(HeatingScheme::allSides()), 0.001);
    }

    public function test_perimeter_only_one_leg_face(): void
    {
        // только лево: база 200 + b1(100) = 300
        $leftOnly = new HeatingScheme(false, false, true, false);
        self::assertEqualsWithDelta(300.0, (new AngleSection(100, 100, 8))->heatedPerimeter($leftOnly), 0.001);
    }

    public function test_rejects_thickness_larger_than_leg(): void
    {
        $this->expectException(AppException::class);
        new AngleSection(100, 6, 8);
    }
}
