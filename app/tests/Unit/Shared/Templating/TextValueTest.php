<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Templating;

use App\Shared\Domain\Templating\TemplateValue;
use App\Shared\Domain\Templating\TextValue;
use PHPUnit\Framework\TestCase;

final class TextValueTest extends TestCase
{
    public function test_holds_value(): void
    {
        self::assertSame('Литамастик 290 Норд', (new TextValue('Литамастик 290 Норд'))->value);
    }

    public function test_allows_empty_string(): void
    {
        self::assertSame('', (new TextValue(''))->value);
    }

    public function test_is_a_template_value(): void
    {
        self::assertInstanceOf(TemplateValue::class, new TextValue('x'));
    }
}
