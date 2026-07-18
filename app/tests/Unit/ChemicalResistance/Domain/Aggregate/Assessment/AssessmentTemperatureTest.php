<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Domain\Aggregate\Assessment;

use App\ChemicalResistance\Domain\Aggregate\Assessment\AssessmentTemperature;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class AssessmentTemperatureTest extends TestCase
{
    public function testDefaultIs40(): void
    {
        self::assertSame(40, AssessmentTemperature::default()->celsius);
    }

    public function testFromIntValid(): void
    {
        self::assertSame(70, AssessmentTemperature::fromInt(70)->celsius);
        self::assertSame(1, AssessmentTemperature::fromInt(1)->celsius);
        self::assertSame(500, AssessmentTemperature::fromInt(500)->celsius);
    }

    /** @dataProvider outOfRange */
    public function testFromIntOutOfRange(int $v): void
    {
        $this->expectException(AppException::class);
        AssessmentTemperature::fromInt($v);
    }

    public static function outOfRange(): array
    {
        return [[0], [-5], [501], [1000]];
    }
}
