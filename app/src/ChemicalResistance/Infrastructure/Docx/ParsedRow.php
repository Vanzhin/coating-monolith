<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Docx;

final readonly class ParsedRow
{
    public function __construct(
        public string $substanceName,
        public string $gradeCell,
    ) {
    }
}
