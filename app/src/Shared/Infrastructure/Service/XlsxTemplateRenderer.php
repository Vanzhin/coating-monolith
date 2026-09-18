<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Service;

use App\Shared\Domain\Templating\ImageValue;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RenderedDocument;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Domain\Templating\TemplateFormat;
use App\Shared\Domain\Templating\TemplateRenderer;
use App\Shared\Domain\Templating\TemplateVariable;
use App\Shared\Domain\Templating\TextValue;
use App\Shared\Domain\Templating\ValidationResult;
use App\Shared\Infrastructure\Exception\AppException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Драйвер xlsx-шаблонов на PhpSpreadsheet. Плейсхолдеры {{key}} / {{key?}} прямо в ячейках.
 * Возможности v1: только текст. Блоки/картинки в xlsx не поддерживаются.
 */
final class XlsxTemplateRenderer implements TemplateRenderer
{
    private const TOKEN = '/\{\{([a-zA-Z0-9_]+)(\?)?\}\}/';

    public function supports(TemplateFile $template): bool
    {
        return TemplateFormat::Xlsx === $template->format;
    }

    public function variables(TemplateFile $template): array
    {
        $spreadsheet = $this->load($template);

        $optionalByName = [];
        $this->eachCellText($spreadsheet, function (string $text) use (&$optionalByName): void {
            if (0 === preg_match_all(self::TOKEN, $text, $matches, PREG_SET_ORDER)) {
                return;
            }
            foreach ($matches as $match) {
                $name = $match[1];
                $optional = '?' === ($match[2] ?? '');
                $optionalByName[$name] = ($optionalByName[$name] ?? false) || $optional;
            }
        });
        $spreadsheet->disconnectWorksheets();

        $variables = [];
        foreach ($optionalByName as $name => $optional) {
            $variables[] = new TemplateVariable((string) $name, $optional);
        }

        return $variables;
    }

    public function validate(TemplateFile $template, RenderData $data): ValidationResult
    {
        $templateNames = [];
        $missing = [];
        $skipped = [];

        foreach ($this->variables($template) as $variable) {
            $templateNames[] = $variable->name;
            if ($data->has($variable->name)) {
                continue;
            }
            if ($variable->optional) {
                $skipped[] = $variable->name;
            } else {
                $missing[] = $variable->name;
            }
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
