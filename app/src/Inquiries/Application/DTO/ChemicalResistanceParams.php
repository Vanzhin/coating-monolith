<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO;

/**
 * Химстойкость: вещества, к которым подбирается покрытие, и температуры их контакта.
 * maxTemperature — рабочая/постоянная (идёт в substanceTemperature фильтра), peakTemperature —
 * пиковая/расчётная (в фильтре химстойкости пика пока нет, храним на будущее). Имена веществ
 * резолвятся в id в маппере (план 2).
 */
final readonly class ChemicalResistanceParams
{
    /** @param list<string> $substances */
    public function __construct(
        public array $substances,
        public ?int $maxTemperature,
        public ?int $peakTemperature,
    ) {
    }
}
