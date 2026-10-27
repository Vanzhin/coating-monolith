<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\BodyCapper;
use PHPUnit\Framework\TestCase;

final class BodyCapperTest extends TestCase
{
    public function test_short_body_is_not_truncated(): void
    {
        $result = BodyCapper::cap('hello', 100);

        self::assertSame('hello', $result['text']);
        self::assertFalse($result['truncated']);
    }

    public function test_long_body_is_cut_and_flagged(): void
    {
        $result = BodyCapper::cap(str_repeat('a', 50), 10);

        self::assertSame(str_repeat('a', 10), $result['text']);
        self::assertTrue($result['truncated']);
    }

    public function test_null_body(): void
    {
        $result = BodyCapper::cap(null, 10);

        self::assertNull($result['text']);
        self::assertFalse($result['truncated']);
    }

    public function test_multibyte_not_split_into_broken_char(): void
    {
        // 10 кириллических букв = 20 байт в UTF-8; режем по 15 байт — граница попадает
        // в середину символа, mb_strcut обязан отступить до целого символа.
        $result = BodyCapper::cap(str_repeat('я', 10), 15);

        self::assertTrue($result['truncated']);
        self::assertSame($result['text'], mb_convert_encoding((string) $result['text'], 'UTF-8', 'UTF-8'));
        self::assertLessThanOrEqual(15, \strlen((string) $result['text']));
        self::assertSame(0, \strlen((string) $result['text']) % 2, 'каждый символ — 2 байта, значит длина чётная');
    }
}
