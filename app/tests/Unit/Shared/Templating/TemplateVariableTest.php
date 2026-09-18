<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Templating;

use App\Shared\Domain\Templating\TemplateVariable;
use PHPUnit\Framework\TestCase;

final class TemplateVariableTest extends TestCase
{
    public function test_required_variable(): void
    {
        $variable = new TemplateVariable('object', false);

        self::assertSame('object', $variable->name);
        self::assertFalse($variable->optional);
    }

    public function test_optional_variable(): void
    {
        self::assertTrue((new TemplateVariable('photo_1', true))->optional);
    }
}
