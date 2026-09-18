<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\File;

use App\Shared\Domain\File\FileConstraints;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class FileConstraintsTest extends TestCase
{
    public function test_exposes_limits(): void
    {
        $c = new FileConstraints(1024, ['image/png'], 800, 600);
        self::assertSame(1024, $c->maxBytes());
        self::assertSame(['image/png'], $c->mimeTypes());
        self::assertSame(800, $c->maxWidth());
        self::assertSame(600, $c->maxHeight());
    }

    public function test_rejects_non_positive_max_bytes(): void
    {
        $this->expectException(AppException::class);
        new FileConstraints(0, ['image/png']);
    }

    public function test_rejects_empty_mime_list(): void
    {
        $this->expectException(AppException::class);
        new FileConstraints(1024, []);
    }
}
