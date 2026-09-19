<?php

declare(strict_types=1);

namespace App\Reports\Domain\Aggregate\Report;

use App\Reports\Domain\Block\BlockKey;

/**
 * Вид отчёта — дискриминатор, определяющий композицию блоков (какие блоки и в каком порядке).
 * Сами блоки — переиспользуемые классы+tagged; тип лишь перечисляет их ключи для своего кейса.
 */
enum ReportType: string
{
    case ReferenceArea = 'reference_area';        // Акт выкрасов эталонного участка
    case TrialApplication = 'trial_application'; // Акт опытного нанесения

    public function label(): string
    {
        return match ($this) {
            self::ReferenceArea => 'Акт выкрасов эталонного участка',
            self::TrialApplication => 'Акт опытного нанесения',
        };
    }

    /**
     * Композиция блоков типа (порядок = порядок в акте). Растёт по мере реализации блоков.
     *
     * @return list<BlockKey>
     */
    public function blockKeys(): array
    {
        return match ($this) {
            self::ReferenceArea => [
                BlockKey::ControlArea,
                BlockKey::SurfacePrep,
                BlockKey::Application,
                BlockKey::Notes,
                BlockKey::Photos,
                BlockKey::Commission,
            ],
            self::TrialApplication => [
                BlockKey::ControlArea,
                BlockKey::SurfacePrep,
                BlockKey::Instruments,
                BlockKey::Application,
                BlockKey::Process,
                BlockKey::Recommendations,
                BlockKey::Conclusion,
                BlockKey::Notes,
                BlockKey::Photos,
                BlockKey::Commission,
            ],
        };
    }
}
