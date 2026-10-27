<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\FileText;

use App\Shared\Infrastructure\FileText\TextNormalizer;
use PHPUnit\Framework\TestCase;

final class TextNormalizerTest extends TestCase
{
    public function test_short_text_not_truncated(): void
    {
        $result = TextNormalizer::normalize('привет', 100);

        self::assertSame('привет', $result['text']);
        self::assertFalse($result['truncated']);
    }

    public function test_long_text_capped_and_flagged(): void
    {
        $result = TextNormalizer::normalize(str_repeat('я', 50), 10);

        self::assertSame(10, mb_strlen($result['text']));
        self::assertTrue($result['truncated']);
    }

    public function test_scrubs_invalid_utf8(): void
    {
        $result = TextNormalizer::normalize("ok\xC3\x28bad", 100);

        self::assertTrue(mb_check_encoding($result['text'], 'UTF-8'));
    }

    public function test_non_whitespace_length_ignores_spaces_and_newlines(): void
    {
        self::assertSame(5, TextNormalizer::nonWhitespaceLength("  a b\n c  d\te  "));
    }
}
