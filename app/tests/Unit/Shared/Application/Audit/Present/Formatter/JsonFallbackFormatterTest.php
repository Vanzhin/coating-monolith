<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Audit\Present\Formatter;

use App\Shared\Application\Audit\Present\Formatter\JsonFallbackFormatter;
use PHPUnit\Framework\TestCase;

final class JsonFallbackFormatterTest extends TestCase
{
    public function test_always_supports(): void
    {
        $formatter = new JsonFallbackFormatter();

        self::assertTrue($formatter->supports(['foo' => 1]));
        self::assertTrue($formatter->supports('x'));
        self::assertTrue($formatter->supports(null));
    }

    public function test_formats_unknown_shape_as_pretty_json_with_unescaped_cyrillic(): void
    {
        $json = (new JsonFallbackFormatter())->format(['foo' => 'бар']);

        self::assertStringContainsString('бар', $json);
        self::assertStringNotContainsString('\\u', $json);
        self::assertStringContainsString("\n", $json);
    }
}
