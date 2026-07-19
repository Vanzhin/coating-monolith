<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Docx;

use App\Shared\Infrastructure\Exception\AppException;

final class DocxAssessmentParser
{
    public function parse(string $path): DocxParseResult
    {
        if (!is_readable($path)) {
            throw new AppException("Файл не найден или недоступен: $path");
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            throw new AppException("Не удалось открыть docx: $path");
        }
        $xml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();

        if ('' === $xml) {
            return new DocxParseResult([], []);
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadXML($xml);
        libxml_clear_errors();

        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $rows = $this->parseRows($xp);
        $notes = $this->parseNotes($xp);

        return new DocxParseResult($rows, $notes);
    }

    /**
     * @return list<ParsedRow>
     *
     * Итерирует по ВСЕМ w:tbl в документе. У Литатанк Классик/Плюс — одна
     * длинная таблица; у Литатанк Стандарт — 23 таблицы (по одной на страницу),
     * каждая с повторным заголовком «Вещество/Стойкость» на первой строке.
     * Rows фильтруются по first-cell = numeric row index, что естественно
     * пропускает заголовки и служебные строки.
     */
    private function parseRows(\DOMXPath $xp): array
    {
        $out = [];

        $tables = $xp->query('//w:tbl');
        if (false === $tables || 0 === $tables->length) {
            return $out;
        }

        foreach ($tables as $table) {
            foreach ($xp->query('.//w:tr', $table) as $tr) {
                $cells = [];
                foreach ($xp->query('.//w:tc', $tr) as $tc) {
                    $text = '';
                    foreach ($xp->query('.//w:t', $tc) as $t) {
                        $text .= $t->textContent;
                    }
                    $cells[] = trim(preg_replace('/\s+/u', ' ', $text));
                }

                if (count($cells) < 3) {
                    continue;
                }

                if (!ctype_digit($cells[0])) {
                    continue;
                }

                $out[] = new ParsedRow($cells[1], $cells[2]);
            }
        }

        return $out;
    }

    /** @return list<ParsedNote> */
    private function parseNotes(\DOMXPath $xp): array
    {
        $paragraphs = [];
        foreach ($xp->query('//w:body/w:p') as $p) {
            $text = '';
            foreach ($xp->query('.//w:t', $p) as $wt) {
                $text .= $wt->textContent;
            }
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            if ('' !== $text) {
                $paragraphs[] = $text;
            }
        }

        $out = [];
        $total = count($paragraphs);
        $i = 0;

        while ($i < $total) {
            if (preg_match('/^Прим(?:ечание)?\s*(\d+)\.\s*(.+)$/u', $paragraphs[$i], $m)) {
                $label = 'Прим. '.$m[1];
                $title = trim($m[2]);
                $descLines = [];
                $j = $i + 1;
                while ($j < $total && !preg_match('/^Прим(?:ечание)?\s*\d+\.\s/u', $paragraphs[$j])) {
                    $descLines[] = $paragraphs[$j];
                    ++$j;
                }
                $out[] = new ParsedNote($label, $title, implode(' ', $descLines));
                $i = $j;
                continue;
            }
            ++$i;
        }

        return $out;
    }
}
