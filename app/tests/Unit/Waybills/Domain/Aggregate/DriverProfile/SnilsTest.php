<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\DriverProfile;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\DriverProfile\Snils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SnilsTest extends TestCase
{
    #[DataProvider('validNumbers')]
    public function test_normalizes_valid_numbers(string $input, string $digits): void
    {
        $snils = new Snils($input);

        self::assertSame($digits, $snils->value());
        self::assertSame(substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6, 3).' '.substr($digits, 9), $snils->formatted());
    }

    /** @return iterable<string, array{string, string}> */
    public static function validNumbers(): iterable
    {
        yield 'digits' => ['11223344595', '11223344595'];
        yield 'formatted' => ['112-233-445 95', '11223344595'];
        yield 'outer whitespace' => [' 112-233-445 95 ', '11223344595'];
        yield 'hyphen before checksum' => ['112-233-445-95', '11223344595'];
        yield 'threshold' => ['00100199899', '00100199899'];
        yield 'checksum 99' => ['00101989999', '00101989999'];
        yield 'sum 100' => ['00101998900', '00101998900'];
        yield 'sum 101' => ['00101999800', '00101999800'];
        yield 'remainder 100' => ['00299998900', '00299998900'];
        yield 'remainder zero' => ['00299999800', '00299999800'];
    }

    #[DataProvider('invalidFormats')]
    public function test_rejects_invalid_format(string $input): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('11 цифр');
        new Snils($input);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFormats(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => ['1122334459'];
        yield 'long' => ['112233445950'];
        yield 'letters' => ['1122334459A'];
        yield 'misplaced separators' => ['112 233 445 95'];
        yield 'extra punctuation' => ['112-233-445/95'];
        yield 'unicode digits' => ['１１２２３３４４５９５'];
    }

    public function test_rejects_incorrect_checksum_without_exposing_the_number(): void
    {
        try {
            new Snils('112-233-445 96');
            self::fail('Неверная контрольная сумма должна отклоняться.');
        } catch (AppException $exception) {
            self::assertStringContainsString('контрольная сумма', $exception->getMessage());
            self::assertStringNotContainsString('112', $exception->getMessage());
        }
    }

    public function test_checksum_is_required_above_threshold(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('контрольная сумма');
        new Snils('00100199999');
    }
}
