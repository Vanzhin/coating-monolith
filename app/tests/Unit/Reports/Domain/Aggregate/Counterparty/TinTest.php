<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports\Domain\Aggregate\Counterparty;

use App\Reports\Domain\Aggregate\Counterparty\Tin;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

/**
 * ИНН: 10 цифр (юрлицо) или 12 (физлицо/ИП) + контрольная сумма. Валидные образцы взяты реальные и
 * прошли контрольную сумму вручную: 7707083893 (Сбербанк, 10), 500100732259 (12).
 */
final class TinTest extends TestCase
{
    public function test_accepts_valid_10_digit(): void
    {
        self::assertSame('7707083893', (new Tin('7707083893'))->value());
    }

    public function test_accepts_valid_12_digit(): void
    {
        self::assertSame('500100732259', (new Tin('500100732259'))->value());
    }

    public function test_trims_surrounding_whitespace(): void
    {
        self::assertSame('7707083893', (new Tin("  7707083893\n"))->value());
    }

    public function test_json_serializes_to_bare_string(): void
    {
        self::assertSame('"7707083893"', json_encode(new Tin('7707083893')));
    }

    public function test_rejects_non_digits(): void
    {
        $this->expectException(AppException::class);
        new Tin('770708389X');
    }

    public function test_rejects_wrong_length(): void
    {
        $this->expectException(AppException::class);
        new Tin('12345678901'); // 11 цифр
    }

    public function test_rejects_empty(): void
    {
        $this->expectException(AppException::class);
        new Tin('   ');
    }

    public function test_rejects_bad_checksum_10(): void
    {
        $this->expectException(AppException::class);
        new Tin('7707083894'); // испорчена контрольная цифра
    }

    public function test_rejects_bad_checksum_12(): void
    {
        $this->expectException(AppException::class);
        new Tin('500100732258'); // испорчена последняя контрольная цифра
    }
}
