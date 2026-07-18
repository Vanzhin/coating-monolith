<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Docx;

final readonly class ParsedAssessment
{
    public function __construct(
        public string $grade,
        public ?int   $maxTemperatureCelsius,
        /** @var list<string> */
        public array  $noteLabels,
    ) {}
}
