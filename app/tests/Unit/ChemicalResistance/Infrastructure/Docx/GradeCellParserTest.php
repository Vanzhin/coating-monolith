<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Infrastructure\Docx;

use App\ChemicalResistance\Infrastructure\Docx\GradeCellParser;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class GradeCellParserTest extends TestCase
{
    /** @dataProvider cases */
    public function testParse(string $input, string $grade, ?int $maxT, array $noteLabels): void
    {
        $out = (new GradeCellParser())->parse($input);
        self::assertSame($grade, $out->grade);
        self::assertSame($maxT, $out->maxTemperatureCelsius);
        self::assertSame($noteLabels, $out->noteLabels);
    }

    public static function cases(): array
    {
        return [
            'plain R'      => ['R',           'R',  null, []],
            'plain NR'     => ['NR',          'NR', null, []],
            'plain LR'     => ['LR',          'LR', null, []],
            'plain FS'     => ['FS',          'FS', null, []],
            'plain NT'     => ['NT',          'NT', null, []],
            'nt-fs'        => ['NT/FS',       'NT', null, []],  // NT takes precedence; document as such
            'with temp º'  => ['R, 60ºC',     'R',  60,   []],
            'with temp °'  => ['R, 60°C',     'R',  60,   []],
            'note single'  => ['R, Прим. 1',  'R',  null, ['Прим. 1']],
            'note multi'   => ['R, Прим. 1,4','R',  null, ['Прим. 1', 'Прим. 4']],
            'note-three-way' => ['R, Прим. 1,4,6', 'R', null, ['Прим. 1', 'Прим. 4', 'Прим. 6']],
            'combined'     => ['R, Прим. 1, 70ºC', 'R', 70, ['Прим. 1']],
            'with dupes'   => ['R, Прим. 1, 70ºC, Прим. 1', 'R', 70, ['Прим. 1']],
            'lowercase c'  => ['R, 60ºc',     'R',  60,   []],
            'spaced R prim'=> ['R,  Прим.  1', 'R', null, ['Прим. 1']],
        ];
    }

    public function testEmptyCellFails(): void
    {
        $this->expectException(AppException::class);
        (new GradeCellParser())->parse('  ');
    }
}
