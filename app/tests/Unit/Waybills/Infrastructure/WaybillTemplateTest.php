<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Infrastructure;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Domain\Templating\TextValue;
use App\Shared\Infrastructure\Service\XlsxTemplateRenderer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class WaybillTemplateTest extends TestCase
{
    public function test_default_template_renders_route_rows_and_totals(): void
    {
        $template = new TemplateFile(dirname(__DIR__, 4).'/src/Waybills/Infrastructure/Resources/templates/waybill.xlsx');
        $renderer = new XlsxTemplateRenderer();
        $values = [];
        foreach ($renderer->variables($template) as $variable) {
            $values[$variable->name] = new TextValue('Тест: '.$variable->name);
        }
        self::assertArrayNotHasKey('organization', $values);
        self::assertArrayNotHasKey('director_name', $values);
        self::assertArrayNotHasKey('director_title', $values);
        $values['distance'] = new TextValue('330');
        $values['odometer_start'] = new TextValue('100000');
        $values['odometer_end'] = new TextValue('100330');
        $values['driver_name'] = new TextValue('Тестовый водитель');

        foreach ([0, 1, 22, 24, 44] as $count) {
            $values['routes'] = new RepeatValue(array_fill(0, $count, [
                'date' => '01.10.2026',
                'odometer_start' => '100000',
                'odometer_end' => '100015',
                'from' => 'База',
                'to' => 'Точка',
                'distance' => '15',
            ]));
            $rendered = $renderer->render($template, new RenderData($values));
            $path = tempnam(sys_get_temp_dir(), 'waybill_test_');
            self::assertNotFalse($path);
            try {
                file_put_contents($path, $rendered->content);
                $book = IOFactory::load($path);
                $sheet = $book->getActiveSheet();

                self::assertSame('Тестовый водитель', $sheet->getCell('D21')->getValue());
                self::assertSame('100000', $sheet->getCell('J27')->getValue());
                self::assertSame('100330', $sheet->getCell('J41')->getValue());
                self::assertSame('330', $sheet->getCell('J39')->getValue());
                self::assertSame('330', $sheet->getCell('F'.(66 + $count))->getValue());
                self::assertSame('Тест: fuel_type', $sheet->getCell('J29')->getValue());
                self::assertSame('Тест: fuel_refilled', $sheet->getCell('J31')->getValue());
                self::assertSame('Тест: fuel_start', $sheet->getCell('J33')->getValue());
                self::assertSame('Тест: fuel_end', $sheet->getCell('J34')->getValue());
                self::assertSame('Тест: fuel_standard', $sheet->getCell('J35')->getValue());
                self::assertSame('Тест: fuel_actual', $sheet->getCell('J36')->getValue());
                self::assertSame('Тест: vehicle_model', $sheet->getCell('D18')->getValue());
                self::assertSame('Тест: registration_number', $sheet->getCell('D20')->getValue());
                for ($row = 65; $row < 65 + $count; ++$row) {
                    self::assertArrayHasKey('E'.$row.':F'.$row, $sheet->getMergeCells());
                    self::assertArrayHasKey('G'.$row.':H'.$row, $sheet->getMergeCells());
                    self::assertSame('База', $sheet->getCell('E'.$row)->getValue());
                    self::assertSame('Точка', $sheet->getCell('G'.$row)->getValue());
                    self::assertSame('15', $sheet->getCell('I'.$row)->getValue());
                    self::assertSame('01.10.2026', $sheet->getCell('B'.$row)->getValue());
                    self::assertSame('100000', $sheet->getCell('C'.$row)->getValue());
                    self::assertSame('100015', $sheet->getCell('D'.$row)->getValue());
                }
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->getCellIterator();
                    $cells->setIterateOnlyExistingCells(true);
                    foreach ($cells as $cell) {
                        self::assertFalse($cell->isFormula());
                        if (is_string($cell->getValue())) {
                            self::assertStringNotContainsString('{{', $cell->getValue());
                        }
                    }
                }
                $book->disconnectWorksheets();
            } finally {
                unlink($path);
            }
        }
    }

    public function test_default_template_does_not_contain_printer_settings(): void
    {
        $path = dirname(__DIR__, 4).'/src/Waybills/Infrastructure/Resources/templates/waybill.xlsx';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                self::assertStringNotContainsString('printerSettings', $zip->getNameIndex($i));
            }
        } finally {
            $zip->close();
        }
    }
}
