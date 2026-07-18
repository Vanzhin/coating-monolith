<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Docx;

final readonly class DocxParseResult
{
    /**
     * @param list<ParsedRow>  $rows
     * @param list<ParsedNote> $notes
     */
    public function __construct(
        public array $rows,
        public array $notes,
    ) {
    }
}
