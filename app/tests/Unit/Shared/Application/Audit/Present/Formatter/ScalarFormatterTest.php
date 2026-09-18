<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Audit\Present\Formatter;

use App\Shared\Application\Audit\Present\Formatter\ScalarFormatter;
use PHPUnit\Framework\TestCase;

final class ScalarFormatterTest extends TestCase
{
    public function test_supports_bool_null_string_int_float(): void
    {
        $formatter = new ScalarFormatter();

        self::assertTrue($formatter->supports(true));
        self::assertTrue($formatter->supports(null));
        self::assertTrue($formatter->supports('x'));
        self::assertTrue($formatter->supports(1));
        self::assertTrue($formatter->supports(1.5));
    }

    public function test_does_not_support_arrays(): void
    {
        self::assertFalse((new ScalarFormatter())->supports(['x' => 1]));
    }

    public function test_true_formats_as_da(): void
    {
        self::assertSame('Да', (new ScalarFormatter())->format(true));
    }

    public function test_false_formats_as_net(): void
    {
        self::assertSame('Нет', (new ScalarFormatter())->format(false));
    }

    public function test_null_formats_as_dash(): void
    {
        self::assertSame('—', (new ScalarFormatter())->format(null));
    }

    public function test_int_string_float_are_returned_as_is(): void
    {
        $formatter = new ScalarFormatter();

        self::assertSame('5', $formatter->format(5));
        self::assertSame('5.5', $formatter->format(5.5));
        self::assertSame('foo', $formatter->format('foo'));
    }
}
