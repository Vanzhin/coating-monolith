<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\DTO;

final readonly class AssessmentDTO
{
    public function __construct(
        public ?string $id,
        public string $coatingId,
        public string $substanceId,
        public string $grade,            // 'R'|'NR'|'LR'|'FS'|'NT'
        public int $maxTemperatureCelsius,
        /** @var list<string> */
        public array $noteIds,
    ) {
    }
}
