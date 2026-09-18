<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Templating;

use App\Shared\Domain\Templating\ImageValue;
use App\Shared\Domain\Templating\TemplateValue;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class ImageValueTest extends TestCase
{
    public function test_holds_path_and_optional_size(): void
    {
        $v = new ImageValue('/tmp/photo.jpg', 400, 300);

        self::assertSame('/tmp/photo.jpg', $v->path);
        self::assertSame(400, $v->width);
        self::assertSame(300, $v->height);
    }

    public function test_size_defaults_to_null(): void
    {
        $v = new ImageValue('/tmp/photo.jpg');

        self::assertNull($v->width);
        self::assertNull($v->height);
    }

    public function test_is_a_template_value(): void
    {
        self::assertInstanceOf(TemplateValue::class, new ImageValue('/tmp/photo.jpg'));
    }

    public function test_rejects_empty_path(): void
    {
        $this->expectException(AppException::class);

        new ImageValue('');
    }

    public function test_rejects_non_positive_width(): void
    {
        $this->expectException(AppException::class);

        new ImageValue('/tmp/photo.jpg', 0);
    }

    public function test_rejects_non_positive_height(): void
    {
        $this->expectException(AppException::class);

        new ImageValue('/tmp/photo.jpg', null, -5);
    }
}
