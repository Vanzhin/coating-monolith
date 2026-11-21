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

    public function test_repeat_copies_horizontal_merges_for_every_row(): void
    {
        $template = $this->template([
            'A3' => '{{routes.from}}',
            'B3' => 'Скрытое значение',
            'C3' => '{{routes.to}}',
            'A4' => 'Итого',
        ], ['A3:B3', 'C3:D3', 'A4:B4']);
        $rows = array_fill(0, 24, ['from' => 'База', 'to' => 'Назначение']);

        $out = $this->renderToSheet($template, new RenderData(['routes' => new RepeatValue($rows)]));

        for ($row = 3; $row < 27; ++$row) {
            self::assertArrayHasKey('A'.$row.':B'.$row, $out->getMergeCells());
            self::assertArrayHasKey('C'.$row.':D'.$row, $out->getMergeCells());
            self::assertSame('База', $out->getCell('A'.$row)->getValue());
            self::assertSame('Скрытое значение', $out->getCell('B'.$row)->getValue());
            self::assertSame('Назначение', $out->getCell('C'.$row)->getValue());
        }
        self::assertArrayHasKey('A27:B27', $out->getMergeCells());
        self::assertSame('Итого', $out->getCell('A27')->getValue());
    }

    public function test_repeat_rejects_merge_crossing_template_row(): void
    {
        $template = $this->template(['A3' => '{{routes.from}}'], ['A3:A4']);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('объединение');
        $this->renderToSheet($template, new RenderData(['routes' => new RepeatValue([
            ['from' => 'База'],
            ['from' => 'Назначение'],
        ])]));
    }

    public function test_single_and_empty_repeats_keep_surrounding_merges(): void
    {
        $template = $this->template([
            'A3' => '{{routes.from}}',
            'A4' => 'Итого',
        ], ['A3:B3', 'A4:B4']);

        $single = $this->renderToSheet($template, new RenderData(['routes' => new RepeatValue([
            ['from' => 'База'],
        ])]));
        self::assertArrayHasKey('A3:B3', $single->getMergeCells());
        self::assertArrayHasKey('A4:B4', $single->getMergeCells());
        self::assertSame('База', $single->getCell('A3')->getValue());

        $empty = $this->renderToSheet($template, new RenderData(['routes' => new RepeatValue([])]));
        self::assertArrayHasKey('A3:B3', $empty->getMergeCells());
        self::assertSame('Итого', $empty->getCell('A3')->getValue());
    }

    public function test_vertical_merge_remains_usable_without_row_expansion(): void
    {
        $template = $this->template(['A3' => '{{routes.from}}'], ['A3:A4']);
        $single = $this->renderToSheet($template, new RenderData(['routes' => new RepeatValue([
            ['from' => 'База'],
        ])]));

        self::assertArrayHasKey('A3:A4', $single->getMergeCells());
        self::assertSame('База', $single->getCell('A3')->getValue());

        $empty = $this->renderToSheet($template, new RenderData(['routes' => new RepeatValue([])]));
        self::assertNotSame('{{routes.from}}', $empty->getCell('A3')->getValue());
    }

    /**
     * @param array<string, string> $cells
     * @param list<string>          $merges
     */
    private function template(array $cells, array $merges = []): TemplateFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($cells as $coord => $text) {
            $sheet->setCellValue($coord, $text);
        }
        foreach ($merges as $merge) {
            $sheet->mergeCells($merge, \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::MERGE_CELL_CONTENT_HIDE);
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
