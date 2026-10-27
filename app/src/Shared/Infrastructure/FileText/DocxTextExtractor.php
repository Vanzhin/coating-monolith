<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\FileText;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\IOFactory;

/**
 * DOCX → текст через phpoffice/phpword. Рекурсивно обходит секции/параграфы/таблицы и
 * собирает текст. XXE отключён средой (libxml ≥2.9 в PHP 8 не грузит внешние сущности).
 */
final readonly class DocxTextExtractor implements FormatTextExtractor
{
    public function extensions(): array
    {
        return ['docx'];
    }

    public function extract(string $path): string
    {
        $phpWord = IOFactory::load($path);

        $lines = [];
        foreach ($phpWord->getSections() as $section) {
            $this->collect($section->getElements(), $lines);
        }

        return implode("\n", array_filter($lines, static fn (string $l): bool => '' !== trim($l)));
    }

    public function emptyMeansScan(): bool
    {
        return false;
    }

    /**
     * @param array<int, AbstractElement> $elements
     * @param list<string>                $lines
     */
    private function collect(array $elements, array &$lines): void
    {
        foreach ($elements as $element) {
            if ($element instanceof Text) {
                $lines[] = (string) $element->getText();
            } elseif ($element instanceof Table) {
                foreach ($element->getRows() as $row) {
                    $cells = [];
                    foreach ($row->getCells() as $cell) {
                        $cellLines = [];
                        $this->collect($cell->getElements(), $cellLines);
                        $cells[] = implode(' ', $cellLines);
                    }
                    $lines[] = implode(' | ', $cells);
                }
            } elseif ($element instanceof AbstractContainer) {
                $this->collect($element->getElements(), $lines);
            }
        }
    }
}
