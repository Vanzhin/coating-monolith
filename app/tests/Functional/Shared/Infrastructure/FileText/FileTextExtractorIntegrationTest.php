<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\FileText;

use App\Shared\Domain\FileText\FileTextExtractor;
use App\Shared\Domain\FileText\FileTextStatus;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FileTextExtractorIntegrationTest extends KernelTestCase
{
    private FileTextExtractor $extractor;
    /** @var list<string> */
    private array $tmpPaths = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $extractor = static::getContainer()->get(FileTextExtractor::class);
        \assert($extractor instanceof FileTextExtractor);
        $this->extractor = $extractor;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpPaths as $path) {
            @unlink($path);
        }
        $this->tmpPaths = [];
    }

    public function test_pdf_text_layer_extracted_via_pdftotext(): void
    {
        $result = $this->extractor->extract(__DIR__.'/fixtures/text-layer.pdf');

        self::assertSame(FileTextStatus::EXTRACTED, $result->status);
        self::assertStringContainsString('EXTRACTED', $result->text);
    }

    public function test_docx_extracted(): void
    {
        $word = new PhpWord();
        $word->addSection()->addText('Покрытие для резервуара дизтоплива');
        $path = $this->tmp('docx');
        WordIOFactory::createWriter($word, 'Word2007')->save($path);

        $result = $this->extractor->extract($path);

        self::assertSame(FileTextStatus::EXTRACTED, $result->status);
        self::assertStringContainsString('резервуара', $result->text);
    }

    public function test_xlsx_extracted(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Нитробензол');
        $sheet->setCellValue('B1', 'плюс 40');
        $path = $this->tmp('xlsx');
        (new Xlsx($spreadsheet))->save($path);

        $result = $this->extractor->extract($path);

        self::assertSame(FileTextStatus::EXTRACTED, $result->status);
        self::assertStringContainsString('Нитробензол', $result->text);
    }

    private function tmp(string $ext): string
    {
        $path = sys_get_temp_dir().'/ftx_'.bin2hex(random_bytes(6)).'.'.$ext;
        $this->tmpPaths[] = $path;

        return $path;
    }
}
