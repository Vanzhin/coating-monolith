<?php

declare(strict_types=1);

namespace App\Tests\Unit\ChemicalResistance\Domain\Aggregate\Substance;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class CasNumberTest extends TestCase
{
    /** @dataProvider validCases */
    public function test_from_string_valid(string $input): void
    {
        $cas = CasNumber::fromString($input);
        self::assertSame($input, $cas->value);
        self::assertSame($input, (string) $cas);
    }

    /** Проверенные CAS чистых веществ + checksum. */
    public static function validCases(): array
    {
        return [
            'water' => ['7732-18-5'],
            'ethanol' => ['64-17-5'],
            'methanol' => ['67-56-1'],
            'acetone' => ['67-64-1'],
            'ethylene-glycol' => ['107-21-1'],
            'toluene' => ['108-88-3'],
            'formaldehyde' => ['50-00-0'],
        ];
    }

    /** @dataProvider invalidCases */
    public function test_from_string_invalid(string $input): void
    {
        $this->expectException(AppException::class);
        CasNumber::fromString($input);
    }

    public static function invalidCases(): array
    {
        return [
            'wrong-checksum' => ['107-21-2'],
            'letters' => ['abc-de-f'],
            'too-short-left' => ['7-18-5'],
            'too-long-left' => ['12345678-18-5'],
            'wrong-format' => ['107215'],
            'empty' => [''],
            'no-dashes' => ['107215'],
            'extra-spaces-inside' => ['107 - 21 - 1'],
        ];
    }

    public function test_equals(): void
    {
        self::assertTrue(CasNumber::fromString('7732-18-5')->equals(CasNumber::fromString('7732-18-5')));
        self::assertFalse(CasNumber::fromString('7732-18-5')->equals(CasNumber::fromString('64-17-5')));
    }
}
