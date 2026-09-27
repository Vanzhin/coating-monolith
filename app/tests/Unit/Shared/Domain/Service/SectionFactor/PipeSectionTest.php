<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Service\SectionFactor;

use App\Shared\Domain\Service\SectionFactor\HeatingScheme;
use App\Shared\Domain\Service\SectionFactor\PipeSection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class PipeSectionTest extends TestCase
{
    public function test_area_is_annulus(): void
    {
        // D=100 t=5: π/4·(100²−90²) = π/4·1900 = 1492.256
        self::assertEqualsWithDelta(1492.256, (new PipeSection(100, 5))->crossSectionArea(), 0.01);
    }

    public function test_perimeter_is_outer_circumference(): void
    {
        // πD = 314.159, обогрев по всему кольцу (схема не влияет)
        self::assertEqualsWithDelta(314.159, (new PipeSection(100, 5))->heatedPerimeter(HeatingScheme::allSides()), 0.01);
    }

    public function test_rejects_wall_thicker_than_radius(): void
    {
        $this->expectException(AppException::class);
        new PipeSection(100, 50);
    }
}
