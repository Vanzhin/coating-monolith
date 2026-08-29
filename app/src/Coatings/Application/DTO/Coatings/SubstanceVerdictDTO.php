<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

/**
 * Вердикт химстойкости покрытия к ОДНОМУ веществу (строка разбивки на странице
 * «Химстойкость» при мультивыборе). grade — код Grade; assessmentId — для админ-правки.
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
    ) {
    }
}
