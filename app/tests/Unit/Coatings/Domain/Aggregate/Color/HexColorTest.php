<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Color;

use App\Coatings\Domain\Aggregate\Color\HexColor;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class HexColorTest extends TestCase
{
    public function test_accepts_valid_uppercase_hex(): void
    {
        self::assertSame('#7A7B7A', (new HexColor('#7A7B7A'))->value);
    }

    public function test_normalizes_lowercase_to_uppercase(): void
    {
        self::assertSame('#ABCDEF', (new HexColor('#abcdef'))->value);
    }

    public function test_rejects_hex_without_hash(): void
    {
        $this->expectException(AppException::class);
        new HexColor('7A7B7A');
    }

    public function test_rejects_wrong_length(): void
    {
        $this->expectException(AppException::class);
        new HexColor('#ABC');
    }

    public function test_rejects_non_hex_characters(): void
    {
        $this->expectException(AppException::class);
        new HexColor('#ZZZZZZ');
    }

    public function test_rejects_empty_string(): void
    {
        $this->expectException(AppException::class);
        new HexColor('');
    }

    public function test_equal_hexes_are_value_equal(): void
    {
        self::assertEquals(new HexColor('#FFFFFF'), new HexColor('#ffffff'));
    }
}
