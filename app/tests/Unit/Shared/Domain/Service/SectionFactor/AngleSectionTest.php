<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Service\SectionFactor;

use App\Shared\Domain\Service\SectionFactor\AngleSection;
use App\Shared\Domain\Service\SectionFactor\HeatingScheme;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class AngleSectionTest extends TestCase
{
    // Три независимые грани — порядок флагов HeatingScheme: top, bottom, left, right.
    // left = наружная полка b1, top = наружная полка b2, right = внутренний контур.
    private static function b1Outer(): HeatingScheme
    {
        return new HeatingScheme(false, false, true, false);
    }

    private static function b2Outer(): HeatingScheme
    {
        return new HeatingScheme(true, false, false, false);
    }

    private static function innerOnly(): HeatingScheme
    {
        return new HeatingScheme(false, false, false, true);
    }

    // Прижат боковой полкой b1 к стене: b1 снята, b2 + внутренний контур греются.
    private static function againstWall(): HeatingScheme
    {
        return new HeatingScheme(true, false, false, true);
    }

    public function test_area_no_fillet(): void
    {
        // равнополочный 100×100×8: (100+100−8)·8 = 1536
        self::assertEqualsWithDelta(1536.0, (new AngleSection(100, 100, 8))->crossSectionArea(), 0.001);
    }

    public function test_perimeter_full_contour(): void
    {
        // все три грани: b1(100) + b2(100) + внутр.контур(b1+b2=200) = 400
        self::assertEqualsWithDelta(400.0, (new AngleSection(100, 100, 8))->heatedPerimeter(HeatingScheme::allSides()), 0.001);
    }

    public function test_perimeter_inner_contour_only(): void
    {
        // только внутренний контур = b1 + b2 = 200
        self::assertEqualsWithDelta(200.0, (new AngleSection(100, 100, 8))->heatedPerimeter(self::innerOnly()), 0.001);
    }

    public function test_perimeter_single_outer_flange(): void
    {
        // только наружная полка b1 = 100 (внутренний контур и вторая полка сняты)
        self::assertEqualsWithDelta(100.0, (new AngleSection(100, 100, 8))->heatedPerimeter(self::b1Outer()), 0.001);
    }

    public function test_perimeter_against_wall(): void
    {
        // b1 у стены: b2(100) + внутренний контур(200) = 300
        self::assertEqualsWithDelta(300.0, (new AngleSection(100, 100, 8))->heatedPerimeter(self::againstWall()), 0.001);
    }

    public function test_unequal_legs_map_to_correct_flag(): void
    {
        // неравнополочный 125×80×10: left обязан дать b1=125, top — b2=80 (ловит перепутанные грани)
        $angle = new AngleSection(125, 80, 10);
        self::assertEqualsWithDelta(125.0, $angle->heatedPerimeter(self::b1Outer()), 0.001);
        self::assertEqualsWithDelta(80.0, $angle->heatedPerimeter(self::b2Outer()), 0.001);
        // полный контур = 2·(125+80) = 410
        self::assertEqualsWithDelta(410.0, $angle->heatedPerimeter(HeatingScheme::allSides()), 0.001);
    }

    public function test_fillets_belong_to_inner_contour(): void
    {
        // скругления (R пятки ×1, r носков ×2) живут во внутреннем контуре, не в наружных полках
        $angle = new AngleSection(100, 100, 8, outerRadius: 10, innerRadius: 5);
        $cornerCoefficient = M_PI / 2 - 2; // длина дуги против прямого угла (отрицательная)
        $expectedInner = 200.0 + 10 * $cornerCoefficient + 2 * 5 * $cornerCoefficient;
        self::assertEqualsWithDelta($expectedInner, $angle->heatedPerimeter(self::innerOnly()), 0.001);
        // наружная полка — ровно длина полки, без скруглений
        self::assertEqualsWithDelta(100.0, $angle->heatedPerimeter(self::b1Outer()), 0.001);
    }

    public function test_rejects_thickness_larger_than_leg(): void
    {
        $this->expectException(AppException::class);
        new AngleSection(100, 6, 8);
    }
}
