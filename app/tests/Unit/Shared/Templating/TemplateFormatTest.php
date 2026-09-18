<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Templating;

use App\Shared\Domain\Templating\TemplateFormat;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class TemplateFormatTest extends TestCase
{
    public function test_from_extension_maps_known_formats(): void
    {
        self::assertSame(TemplateFormat::Docx, TemplateFormat::fromExtension('docx'));
        self::assertSame(TemplateFormat::Xlsx, TemplateFormat::fromExtension('xlsx'));
    }

    public function test_from_extension_is_case_insensitive(): void
    {
        self::assertSame(TemplateFormat::Docx, TemplateFormat::fromExtension('DOCX'));
        self::assertSame(TemplateFormat::Xlsx, TemplateFormat::fromExtension('Xlsx'));
    }

    public function test_from_extension_rejects_unknown(): void
    {
        $this->expectException(AppException::class);

        TemplateFormat::fromExtension('pdf');
    }

    public function test_mime_type_per_format(): void
    {
        self::assertStringContainsString('wordprocessingml', TemplateFormat::Docx->mimeType());
        self::assertStringContainsString('spreadsheetml', TemplateFormat::Xlsx->mimeType());
    }
}
