<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

use App\ChemicalResistance\Application\DTO\AssessmentNoteDTO;

/**
 * Вердикт химстойкости покрытия к ОДНОМУ веществу (строка разбивки на странице
 * «Химстойкость» при мультивыборе). grade — код Grade; assessmentId — для админ-правки;
 * notes — примечания оценки (из read-модели ChemicalResistance) для значка и popover.
 */
final readonly class SubstanceVerdictDTO
{
    public function __construct(
        public string $substanceId,
        public string $substanceName,
        public string $grade,
        public string $gradeLabel,
        public ?int $maxTemperature,
        public string $assessmentId,
        /** @var list<AssessmentNoteDTO> */
        public array $notes = [],
    ) {
    }
}
