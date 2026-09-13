<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Service;

use App\Coatings\Domain\Service\FilmThicknessCalculator;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class FilmThicknessCalculatorTest extends TestCase
{
    private FilmThicknessCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new FilmThicknessCalculator();
    }

    public function test_wet_from_dry_without_dilution(): void
    {
        // VS 50%, без разбавления → мокрая вдвое толще сухой.
        self::assertSame(100.0, $this->calc->wetFromDry(new Percent(50), new Percent(0), new PositiveNumber(50)));
    }

    public function test_wet_from_dry_with_dilution(): void
    {
        // VS 65%, разбавление 10% → VS_эфф ≈ 59.09%, WFT ≈ 135.4 мкм для DFT 80.
        self::assertEqualsWithDelta(
            135.38,
            $this->calc->wetFromDry(new Percent(65), new Percent(10), new PositiveNumber(80)),
            0.05,
        );
    }

    public function test_dry_from_wet_is_inverse(): void
    {
        $wet = $this->calc->wetFromDry(new Percent(65), new Percent(10), new PositiveNumber(80));
        self::assertEqualsWithDelta(
            80.0,
            $this->calc->dryFromWet(new Percent(65), new Percent(10), new PositiveNumber($wet)),
            0.0001,
        );
    }

    public function test_effective_solids_drops_with_dilution(): void
    {
        self::assertEqualsWithDelta(59.0909, $this->calc->effectiveSolidsPercent(new Percent(65), new Percent(10)), 0.001);
        self::assertSame(65.0, $this->calc->effectiveSolidsPercent(new Percent(65), new Percent(0)));
    }

    public function test_rejects_zero_volume_solids(): void
    {
        $this->expectException(AppException::class);
        $this->calc->effectiveSolidsPercent(new Percent(0), new Percent(0));
    }
}
