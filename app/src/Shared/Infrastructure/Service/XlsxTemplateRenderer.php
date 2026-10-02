<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\ImageValue;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RenderedDocument;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Domain\Templating\TemplateFormat;
use App\Shared\Domain\Templating\TemplateRenderer;
use App\Shared\Domain\Templating\TemplateVariable;
use App\Shared\Domain\Templating\TextValue;
use App\Shared\Domain\Templating\ValidationResult;
use App\Shared\Infrastructure\Exception\AppException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Драйвер xlsx-шаблонов на PhpSpreadsheet. Плейсхолдеры {{key}} / {{key?}} прямо в ячейках + повтор строки:
 * строка с токенами {{group.sub}} клонируется по числу строк группы (RepeatValue), подполя подставляются.
 * Повтор-строка опциональна по природе (пустой список → строка удаляется). Картинки/мерджи в xlsx не поддержаны.
 */
final class XlsxTemplateRenderer implements TemplateRenderer
{
    private const TOKEN = '/\{\{([a-zA-Z0-9_]+)(\?)?\}\}/';
    private const REPEAT_TOKEN = '/\{\{([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)\}\}/';

    public function supports(TemplateFile $template): bool
    {
        return TemplateFormat::Xlsx === $template->format;
    }

    public function variables(TemplateFile $template): array
    {
        $spreadsheet = $this->load($template);
        $scan = $this->scan($spreadsheet);
        $spreadsheet->disconnectWorksheets();

        $variables = [];
        foreach ($scan['flat'] as $name => $optional) {
            $variables[] = new TemplateVariable((string) $name, $optional);
        }

        return $variables;
    }

    public function validate(TemplateFile $template, RenderData $data): ValidationResult
    {
        $spreadsheet = $this->load($template);
        $scan = $this->scan($spreadsheet);
        $spreadsheet->disconnectWorksheets();

        $missing = [];
        $skipped = [];
        $templateNames = [];

        foreach ($scan['flat'] as $name => $optional) {
            $name = (string) $name;
            $templateNames[] = $name;
            if ($data->has($name)) {
                continue;
            }
            if ($optional) {
                $skipped[] = $name;
            } else {
                $missing[] = $name;
            }
        }
        // Повтор-строки опциональны по природе — пустой список допустим (строка удалится), в missing не идут.
        foreach ($scan['repeats'] as $group) {
            $templateNames[] = $group;
        }

        $unused = array_values(array_diff($data->variableNames(), $templateNames));

        return new ValidationResult(missing: $missing, skipped: $skipped, unused: $unused);
    }

    public function render(TemplateFile $template, RenderData $data): RenderedDocument
    {
        $result = $this->validate($template, $data);
        if (!$result->isValid()) {
            throw new AppException(sprintf('Не заполнены обязательные поля: %s.', implode(', ', $result->missing)), log: ['missing' => $result->missing]);
        }

        $spreadsheet = $this->load($template);
        $this->expandRepeats($spreadsheet, $data);

        $this->eachCell($spreadsheet, function (Cell $cell) use ($data): void {
            $value = $cell->getValue();
            if (!is_string($value) || !str_contains($value, '{{')) {
                return;
            }
            $filled = preg_replace_callback(self::TOKEN, fn (array $match): string => $this->substitute($match, $data), $value);
            // явный STRING: иначе значение с ведущим '=' стало бы формулой, а числовая строка — числом
            $cell->setValueExplicit($filled ?? $value, DataType::TYPE_STRING);
        });

        $bytes = $this->toBytes($spreadsheet);
        $spreadsheet->disconnectWorksheets();

        return new RenderedDocument($bytes, TemplateFormat::Xlsx);
    }

    /**
     * Разворачивает повтор-строки: для каждой группы находит строку-шаблон с {{group.sub}} и клонирует её по
     * числу строк RepeatValue (пусто → удаляет). Группы обрабатываются по очереди, строка ищется заново —
     * вставка/удаление сдвигает индексы, повторный поиск их учитывает.
     */
    private function expandRepeats(Spreadsheet $spreadsheet, RenderData $data): void
    {
        $groups = $this->scan($spreadsheet)['repeats'];
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            foreach ($groups as $group) {
                $value = $data->get($group);
                $rows = $value instanceof RepeatValue ? $value->rows : [];
                $this->expandGroup($sheet, $group, $rows);
            }
        }
    }

    /**
     * @param list<array<string, string|ImageValue>> $rows
     */
    private function expandGroup(Worksheet $sheet, string $group, array $rows): void
    {
        $templateRow = $this->findGroupRow($sheet, $group);
        if (null === $templateRow) {
            return; // группы нет в этом листе
        }
        if ([] === $rows) {
            $sheet->removeRow($templateRow, 1);

            return;
        }

        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $templateValues = [];
        for ($col = 1; $col <= $highestCol; ++$col) {
            $templateValues[$col] = $sheet->getCell([$col, $templateRow])->getValue();
        }
        $height = $sheet->getRowDimension($templateRow)->getRowHeight();

        if (count($rows) > 1) {
            $sheet->insertNewRowBefore($templateRow + 1, count($rows) - 1);
        }

        foreach ($rows as $i => $rowData) {
            $target = $templateRow + $i;
            for ($col = 1; $col <= $highestCol; ++$col) {
                $letter = Coordinate::stringFromColumnIndex($col);
                if ($i > 0) {
                    $sheet->duplicateStyle($sheet->getStyle($letter.$templateRow), $letter.$target);
                }
                $tpl = $templateValues[$col];
                if (!is_string($tpl)) {
                    continue;
                }
                $sheet->getCell([$col, $target])->setValueExplicit($this->fillRepeatCell($tpl, $group, $rowData), DataType::TYPE_STRING);
            }
            if ($i > 0) {
                $sheet->getRowDimension($target)->setRowHeight($height);
            }
        }
    }

    private function findGroupRow(Worksheet $sheet, string $group): ?int
    {
        $pattern = '/\{\{'.preg_quote($group, '/').'\.[a-zA-Z0-9_]+\}\}/';
        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->getCellIterator();
            $cells->setIterateOnlyExistingCells(true);
            foreach ($cells as $cell) {
                $value = $cell->getValue();
                if (is_string($value) && 1 === preg_match($pattern, $value)) {
                    return $row->getRowIndex();
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, string|ImageValue> $rowData
     */
    private function fillRepeatCell(string $text, string $group, array $rowData): string
    {
        $filled = preg_replace_callback(self::REPEAT_TOKEN, static function (array $match) use ($group, $rowData): string {
            if ($match[1] !== $group) {
                return $match[0]; // чужая группа — не трогаем (развернётся своим проходом)
            }
            $value = $rowData[$match[2]] ?? '';

            return $value instanceof ImageValue ? '' : (string) $value; // картинки xlsx не поддерживает
        }, $text);

        return $filled ?? $text;
    }

    /**
     * @param array<int, string> $match
     */
    private function substitute(array $match, RenderData $data): string
    {
        $name = $match[1];
        $value = $data->get($name);

        if (null === $value) {
            return ''; // опциональный пропущенный чистим; обязательные отсечены validate
        }
        if ($value instanceof TextValue) {
            return $value->value;
        }
        if ($value instanceof ImageValue) {
            throw new AppException(sprintf('xlsx-шаблон не поддерживает картинки (поле «%s»).', $name));
        }

        return '';
    }

    /**
     * Единый проход по тексту ячеек: плоские переменные (имя → опциональность) + имена повтор-групп.
     *
     * @return array{flat: array<string, bool>, repeats: list<string>}
     */
    private function scan(Spreadsheet $spreadsheet): array
    {
        $flat = [];
        $repeats = [];
        $this->eachCellText($spreadsheet, function (string $text) use (&$flat, &$repeats): void {
            if (preg_match_all(self::REPEAT_TOKEN, $text, $rm, PREG_SET_ORDER)) {
                foreach ($rm as $match) {
                    $repeats[$match[1]] = true;
                }
            }
            if (preg_match_all(self::TOKEN, $text, $fm, PREG_SET_ORDER)) {
                foreach ($fm as $match) {
                    $name = $match[1];
                    $flat[$name] = ($flat[$name] ?? false) || '?' === ($match[2] ?? '');
                }
            }
        });

        return ['flat' => $flat, 'repeats' => array_keys($repeats)];
    }

    private function load(TemplateFile $template): Spreadsheet
    {
        if (!is_readable($template->path)) {
            throw new AppException(sprintf('Файл шаблона недоступен: «%s».', $template->path));
        }

        try {
            return IOFactory::load($template->path);
        } catch (\Throwable $e) {
            throw new AppException('Не удалось открыть xlsx-шаблон.', log: ['path' => $template->path], previous: $e);
        }
    }

    private function toBytes(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx_out_');
        if (false === $path) {
            throw new AppException('Не удалось создать временный файл для xlsx.');
        }

        try {
            (new XlsxWriter($spreadsheet))->save($path);
            $bytes = file_get_contents($path);
            if (false === $bytes) {
                throw new AppException('Не удалось прочитать сгенерированный xlsx.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }

    private function eachCell(Spreadsheet $spreadsheet, callable $fn): void
    {
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->getCellIterator();
                $cells->setIterateOnlyExistingCells(true);
                foreach ($cells as $cell) {
                    $fn($cell);
                }
            }
        }
    }

    private function eachCellText(Spreadsheet $spreadsheet, callable $fn): void
    {
        $this->eachCell($spreadsheet, function (Cell $cell) use ($fn): void {
            $value = $cell->getValue();
            if (is_string($value)) {
                $fn($value);
            }
        });
    }
}
