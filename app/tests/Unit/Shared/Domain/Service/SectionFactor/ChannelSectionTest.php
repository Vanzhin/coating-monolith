<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Service\SectionFactor;

use App\Shared\Domain\Service\SectionFactor\ChannelSection;
use App\Shared\Domain\Service\SectionFactor\HeatingScheme;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class ChannelSectionTest extends TestCase
{
    public function test_area_parallel_flanges_no_fillet(): void
    {
        // H=200 b=76 s=5 t=9, u=0: 200·5 + 2·(76−5)·9 = 1000 + 1278 = 2278
        self::assertEqualsWithDelta(2278.0, (new ChannelSection(200, 76, 5, 9))->crossSectionArea(), 0.001);
    }

    public function test_perimeter_four_sided_parallel_flanges(): void
    {
        // u=0: top(76)+bottom(76)+left(200)+right(200+2·71)=76+76+200+342 = 694
        self::assertEqualsWithDelta(694.0, (new ChannelSection(200, 76, 5, 9))->heatedPerimeter(HeatingScheme::allSides()), 0.001);
    }

    public function test_perimeter_three_sided_drops_top(): void
    {
        // без верха: bottom(76)+left(200)+right(342) = 618
        self::assertEqualsWithDelta(618.0, (new ChannelSection(200, 76, 5, 9))->heatedPerimeter(HeatingScheme::threeSided()), 0.001);
    }

    public function test_rejects_web_thicker_than_flange_width(): void
    {
        $this->expectException(AppException::class);
        new ChannelSection(200, 5, 6, 9);
    }
}
