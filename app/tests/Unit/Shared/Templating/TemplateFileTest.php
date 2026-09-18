<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Templating;

use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Domain\Templating\TemplateFormat;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class TemplateFileTest extends TestCase
{
    public function test_docx_path_and_format(): void
    {
        $file = new TemplateFile('/tpl/report.docx');

        self::assertSame('/tpl/report.docx', $file->path);
        self::assertSame(TemplateFormat::Docx, $file->format);
    }

    public function test_xlsx_format(): void
    {
        self::assertSame(TemplateFormat::Xlsx, (new TemplateFile('/tpl/kp.xlsx'))->format);
    }

    public function test_extension_is_case_insensitive(): void
    {
        self::assertSame(TemplateFormat::Docx, (new TemplateFile('/tpl/Report.DOCX'))->format);
    }

    public function test_rejects_empty_path(): void
    {
        $this->expectException(AppException::class);

        new TemplateFile('');
    }

    public function test_rejects_unknown_extension(): void
    {
        $this->expectException(AppException::class);

        new TemplateFile('/tpl/report.pdf');
    }

    public function test_rejects_missing_extension(): void
    {
        $this->expectException(AppException::class);

        new TemplateFile('/tpl/report');
    }
}
