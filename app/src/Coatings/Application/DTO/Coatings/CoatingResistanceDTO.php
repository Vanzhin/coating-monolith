<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

/**
 * Покрытие + его химстойкость к выбранным веществам (страница «Химстойкость», мультивыбор).
 * При нескольких веществах пилюля показывает ХУДШИЙ вердикт: grade — самый слабый грейд
 * среди выбранных (R→LR→NR), maxTemperature — самая ограничивающая (минимальная) температура.
 * verdicts — разбивка по каждому веществу (для админ-правки и подробностей).
 *
 * @param SubstanceVerdictDTO[] $verdicts
 */
final readonly class CoatingResistanceDTO
{
    /**
     * @param SubstanceVerdictDTO[] $verdicts
     */
    public function __construct(
        public CoatingDTO $coating,
        public string $grade,
        public string $gradeLabel,
        public ?int $maxTemperature,
        public array $verdicts,
    ) {
    }
}
