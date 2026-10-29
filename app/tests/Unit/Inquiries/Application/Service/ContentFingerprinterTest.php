<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Application\Service;

use App\Inquiries\Application\Service\ContentFingerprinter;
use PHPUnit\Framework\TestCase;

final class ContentFingerprinterTest extends TestCase
{
    public function test_same_text_same_hash(): void
    {
        $f = new ContentFingerprinter();

        self::assertSame($f->hash('нефть до 90 °C'), $f->hash('нефть до 90 °C'));
    }

    public function test_whitespace_and_trim_normalized(): void
    {
        $f = new ContentFingerprinter();

        // лишние пробелы/переносы/края не меняют отпечаток
        self::assertSame($f->hash('нефть до 90 °C'), $f->hash("  нефть   до\n90   °C  "));
    }

    public function test_different_text_different_hash(): void
    {
        $f = new ContentFingerprinter();

        self::assertNotSame($f->hash('нефть до 90 °C'), $f->hash('нефть до 80 °C'));
    }
}
