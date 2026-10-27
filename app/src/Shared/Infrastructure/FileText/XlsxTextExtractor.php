<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\FileText;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * XLSX/XLS → текст через phpoffice/phpspreadsheet. setReadDataOnly(true) + чтение без
 * вычисления формул — читаем ТОЛЬКО значения, формулы не исполняем (защита от формула-
 * инъекций в недоверенном файле). XXE отключён средой (libxml ≥2.9 в PHP 8 не грузит
 * внешние сущности по умолчанию). Каждый лист — блок строк «ячейка | ячейка».
 */
final readonly class XlsxTextExtractor implements FormatTextExtractor
{
    public function extensions(): array
    {
        return ['xlsx', 'xls'];
    }

    public function extract(string $path): string
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $lines = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $lines[] = '=== Лист: '.$sheet->getTitle().' ===';
            foreach ($sheet->toArray(null, false, false, false) as $row) {
                $cells = array_values(array_filter(
                    array_map(static fn ($c): string => trim((string) $c), $row),
                    static fn (string $c): bool => '' !== $c,
                ));
                if ([] !== $cells) {
                    $lines[] = implode(' | ', $cells);
                }
            }
        }

        return implode("\n", $lines);
    }

    public function emptyMeansScan(): bool
    {
        return false;
    }
}
