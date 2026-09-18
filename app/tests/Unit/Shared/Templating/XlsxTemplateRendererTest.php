<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Templating;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Domain\Templating\TextValue;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Service\XlsxTemplateRenderer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;

final class XlsxTemplateRendererTest extends TestCase
{
    private string $templatePath;
    private XlsxTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', '{{project}}');
        $sheet->setCellValue('A2', '{{object}}');
        $sheet->setCellValue('B1', 'прим. {{comment?}}');

        $this->templatePath = sys_get_temp_dir().'/tpl_'.uniqid().'.xlsx';
        (new XlsxWriter($spreadsheet))->save($this->templatePath);
        $spreadsheet->disconnectWorksheets();

        $this->renderer = new XlsxTemplateRenderer();
    }

    protected function tearDown(): void
    {
        @unlink($this->templatePath);
    }

    private function template(): TemplateFile
    {
        return new TemplateFile($this->templatePath);
    }

    public function test_variables_lists_placeholders_with_optional_flag(): void
    {
        $byName = [];
        foreach ($this->renderer->variables($this->template()) as $variable) {
            $byName[$variable->name] = $variable->optional;
        }

        self::assertCount(3, $byName);
        self::assertFalse($byName['project']);
        self::assertFalse($byName['object']);
        self::assertTrue($byName['comment']);
    }

    public function test_validate_reports_missing_skipped_unused(): void
    {
        $result = $this->renderer->validate($this->template(), new RenderData([
            'project' => new TextValue('Усольский ГОК'),
            'stray' => new TextValue('x'),
        ]));

        self::assertSame(['object'], $result->missing);
        self::assertSame(['comment'], $result->skipped);
        self::assertSame(['stray'], $result->unused);
        self::assertFalse($result->isValid());
    }

    public function test_render_fills_cells_and_blanks_optional(): void
    {
        $doc = $this->renderer->render($this->template(), new RenderData([
            'project' => new TextValue('Усольский ГОК'),
            'object' => new TextValue('Балка Б2-3'),
        ]));

        self::assertSame('xlsx', $doc->extension());

        $sheet = $this->loadFromString($doc->content)->getActiveSheet();
        self::assertSame('Усольский ГОК', $sheet->getCell('A1')->getValue());
        self::assertSame('Балка Б2-3', $sheet->getCell('A2')->getValue());
        self::assertSame('прим. ', $sheet->getCell('B1')->getValue());
    }

    public function test_render_keeps_equals_leading_value_as_text(): void
    {
        $doc = $this->renderer->render($this->template(), new RenderData([
            'project' => new TextValue('=Н/Д'),
            'object' => new TextValue('Балка Б2-3'),
        ]));

        $cell = $this->loadFromString($doc->content)->getActiveSheet()->getCell('A1');
        self::assertFalse($cell->isFormula(), 'значение с ведущим = не должно стать формулой');
        self::assertSame('=Н/Д', $cell->getValue());
    }

    public function test_render_throws_on_missing_required(): void
    {
        $this->expectException(AppException::class);

        $this->renderer->render($this->template(), new RenderData([
            'project' => new TextValue('Усольский ГОК'),
        ]));
    }

    private function loadFromString(string $bytes): Spreadsheet
    {
        $path = sys_get_temp_dir().'/out_'.uniqid().'.xlsx';
        file_put_contents($path, $bytes);
        $spreadsheet = IOFactory::load($path);
        @unlink($path);

        return $spreadsheet;
    }
}
