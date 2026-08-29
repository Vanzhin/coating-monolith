<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\DTO;

/**
 * Оценка химстойкости одного покрытия по веществу (для обратного поиска «вещество →
 * покрытия»). grade — код Grade (R/LR/NR…); suitable — стойкое ли (Grade::isSuitable);
 * assessmentId — для inline-правки админом.
 */
final readonly class ResistantCoatingDTO
{
    public function __construct(
        public string $coatingId,
        public string $grade,
        public string $gradeLabel,
        public int $maxTemperature,
        public string $assessmentId,
        public bool $suitable,
    ) {
    }
}
