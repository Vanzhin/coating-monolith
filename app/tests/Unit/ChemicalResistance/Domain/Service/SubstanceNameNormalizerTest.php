<?php
declare(strict_types=1);
namespace App\Tests\Unit\ChemicalResistance\Domain\Service;

use App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer;
use PHPUnit\Framework\TestCase;

final class SubstanceNameNormalizerTest extends TestCase
{
    /** @dataProvider sameGroup */
    public function testAllInGroupNormalizeSame(array $variants): void
    {
        $first = SubstanceNameNormalizer::normalize($variants[0]);
        foreach ($variants as $v) {
            self::assertSame($first, SubstanceNameNormalizer::normalize($v), "'{$v}' expected to match '{$variants[0]}'");
        }
    }

    public static function sameGroup(): array
    {
        return [
            'ethanediol synonyms' => [[
                '1,2-Ethanediol',
                '1,2 - Ethanediol',
                '1,2-ETHANEDIOL',
            ]],
            'butanone synonyms'   => [[
                '2-Butanone',
                '2-Butanone (*Shell)',
            ]],
            'trademark stripping' => [[
                '00813 Marine Diesel Blend* (*™ Famm)',
                '00813 Marine Diesel Blend',
            ]],
            'language-markers stripped' => [[
                'Ethanol',
                'Ethanol (G)',
                'ETHANOL (n)',
            ]],
        ];
    }

    /** @dataProvider distinctGroup */
    public function testDifferentSubstancesNormalizeDifferently(string $a, string $b): void
    {
        self::assertNotSame(
            SubstanceNameNormalizer::normalize($a),
            SubstanceNameNormalizer::normalize($b),
            "'{$a}' and '{$b}' must not be normalized to the same key",
        );
    }

    public static function distinctGroup(): array
    {
        return [
            'ethanediol vs dihydroxyethane' => ['1,2-Ethanediol', '1,2-Dihydroxyethane'],
            'butanone vs methylpropylketone' => ['2-Butanone', '2-methylpropylmethylketone'],
            'water vs waters' => ['Water', 'Waters'],
            'russian vs english' => ['Вода', 'Water'],
        ];
    }
}
