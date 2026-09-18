<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Templating;

use App\Shared\Domain\Templating\RenderedDocument;
use App\Shared\Domain\Templating\TemplateFormat;
use PHPUnit\Framework\TestCase;

final class RenderedDocumentTest extends TestCase
{
    public function test_docx_metadata(): void
    {
        $doc = new RenderedDocument('BYTES', TemplateFormat::Docx);

        self::assertSame('BYTES', $doc->content);
        self::assertSame('docx', $doc->extension());
        self::assertStringContainsString('wordprocessingml', $doc->mimeType());
    }

    public function test_xlsx_metadata(): void
    {
        $doc = new RenderedDocument('BYTES', TemplateFormat::Xlsx);

        self::assertSame('xlsx', $doc->extension());
        self::assertStringContainsString('spreadsheetml', $doc->mimeType());
    }
}
