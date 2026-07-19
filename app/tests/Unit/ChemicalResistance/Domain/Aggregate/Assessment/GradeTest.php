<?php

declare(strict_types=1);

namespace App\Tests\Unit\ChemicalResistance\Domain\Aggregate\Assessment;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use PHPUnit\Framework\TestCase;

final class GradeTest extends TestCase
{
    /** @dataProvider suitableCases */
    public function test_is_suitable(Grade $g, bool $expected): void
    {
        self::assertSame($expected, $g->isSuitable());
    }

    public static function suitableCases(): array
    {
        return [
            'R' => [Grade::R,  true],
            'LR' => [Grade::LR, true],
            'NR' => [Grade::NR, false],
            'FS' => [Grade::FS, false],
            'NT' => [Grade::NT, false],
        ];
    }

    public function test_from_string_unknown(): void
    {
        $this->expectException(\ValueError::class);
        Grade::from('XX');
    }
}
