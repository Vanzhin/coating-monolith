<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Docx;

final readonly class ParsedNote
{
    public function __construct(
        public string $label,
        public string $title,
        public string $description,
    ) {
    }
}
