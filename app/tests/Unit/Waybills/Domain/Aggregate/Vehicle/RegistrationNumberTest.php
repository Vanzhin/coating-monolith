<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Vehicle;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistrationNumberTest extends TestCase
{
    #[DataProvider('validNumbers')]
    public function test_accepts_and_normalizes_valid_numbers(string $input, string $expected): void
    {
        self::assertSame($expected, (new RegistrationNumber($input))->value());
    }

    /** @return iterable<string, array{string, string}> */
    public static function validNumbers(): iterable
    {
        yield 'two-digit region' => ['A123BC77', 'a123bc77'];
        yield 'three-digit region' => ['M001MM797', 'm001mm797'];
        yield 'mixed case' => ['x960Ta777', 'x960ta777'];
        yield 'zero region' => ['A001AA00', 'a001aa00'];
        foreach (str_split('ABEKMHOPCTYX') as $letter) {
            yield 'allowed letter '.$letter => [$letter.'123'.$letter.$letter.'77', strtolower($letter).'123'.strtolower($letter.$letter).'77'];
        }
    }

    public function test_formats_number_with_uppercase_letters(): void
    {
        self::assertSame('X121TA777', (new RegistrationNumber('x121ta777'))->formatted());
    }

    #[DataProvider('invalidNumbers')]
    public function test_rejects_invalid_numbers(string $number): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('формате A123BC77');
        new RegistrationNumber($number);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNumbers(): iterable
    {
        yield 'empty' => [''];
        yield 'number 000' => ['A000BC77'];
        yield 'forbidden first letter' => ['D123BC77'];
        yield 'forbidden second letter' => ['A123DC77'];
        yield 'forbidden third letter' => ['A123BD77'];
        yield 'two digits in number' => ['A12BC77'];
        yield 'four digits in number' => ['A1234BC77'];
        yield 'one-digit region' => ['A123BC7'];
        yield 'four-digit region' => ['A123BC7777'];
        yield 'cyrillic' => ['А123ВС77'];
        yield 'mixed alphabets' => ['A123ВC77'];
        yield 'spaces' => ['A123 BC77'];
        yield 'leading whitespace' => [' A123BC77'];
        yield 'punctuation' => ['A123-BC77'];
        yield 'trailing newline' => ["A123BC77\n"];
        yield 'unicode digits' => ['A１２３BC77'];
    }
}
