<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
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
    private XlsxTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new XlsxTemplateRenderer();
    }

    public function test_flat_substitution(): void
    {
        $template = $this->template(['A1' => '{{title}}', 'A2' => 'подпись {{who?}}']);
        $out = $this->renderToSheet($template, new RenderData([
            'title' => new TextValue('Карточка СИЗ'),
            'who' => new TextValue('Иванов'),
        ]));

        self::assertSame('Карточка СИЗ', $out->getCell('A1')->getValue());
        self::assertSame('подпись Иванов', $out->getCell('A2')->getValue());
    }

    public function test_optional_missing_is_blanked(): void
    {
        $template = $this->template(['A1' => '{{title}}', 'A2' => 'подпись {{who?}}']);
        $out = $this->renderToSheet($template, new RenderData(['title' => new TextValue('Карточка')]));

        self::assertSame('подпись ', $out->getCell('A2')->getValue());
    }

    public function test_required_missing_throws(): void
    {
        $template = $this->template(['A1' => '{{title}}']);

        $this->expectException(AppException::class);
        $this->renderToSheet($template, new RenderData([]));
    }

    public function test_repeat_rows_expand_and_shift_below(): void
    {
        $template = $this->template([
            'A1' => '{{title}}',
            'A3' => '{{items.name}}', 'B3' => '{{items.qty}}',
            'A4' => 'Итого',
        ]);
        $out = $this->renderToSheet($template, new RenderData([
            'title' => new TextValue('Карточка'),
            'items' => new RepeatValue([
                ['name' => 'Перчатки', 'qty' => '10'],
                ['name' => 'Каска', 'qty' => '1'],
                ['name' => 'Очки', 'qty' => '2'],
            ]),
        ]));

        self::assertSame('Перчатки', $out->getCell('A3')->getValue());
        self::assertSame('10', $out->getCell('B3')->getValue());
        self::assertSame('Каска', $out->getCell('A4')->getValue());
        self::assertSame('Очки', $out->getCell('A5')->getValue());
        self::assertSame('Итого', $out->getCell('A6')->getValue(), 'строка под повтором сдвинулась на 2 (3 позиции)');
    }

    public function test_single_repeat_row_keeps_position(): void
    {
        $template = $this->template(['A3' => '{{items.name}}', 'A4' => 'Итого']);
        $out = $this->renderToSheet($template, new RenderData([
            'items' => new RepeatValue([['name' => 'Перчатки']]),
        ]));

        self::assertSame('Перчатки', $out->getCell('A3')->getValue());
        self::assertSame('Итого', $out->getCell('A4')->getValue());
    }

    public function test_empty_repeat_removes_template_row(): void
    {
        $template = $this->template(['A3' => '{{items.name}}', 'A4' => 'Итого']);
        $out = $this->renderToSheet($template, new RenderData(['items' => new RepeatValue([])]));

        self::assertSame('Итого', $out->getCell('A3')->getValue(), 'пустой повтор удалил строку-шаблон, Итого поднялось');
    }

    /** @param array<string, string> $cells */
    private function template(array $cells): TemplateFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($cells as $coord => $text) {
            $sheet->setCellValue($coord, $text);
        }
        $path = tempnam(sys_get_temp_dir(), 'xlsx_tpl_').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new TemplateFile($path);
    }

    private function renderToSheet(TemplateFile $template, RenderData $data): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $rendered = $this->renderer->render($template, $data);
        $path = tempnam(sys_get_temp_dir(), 'xlsx_res_').'.xlsx';
        file_put_contents($path, $rendered->content);

        return IOFactory::load($path)->getActiveSheet();
    }
}
