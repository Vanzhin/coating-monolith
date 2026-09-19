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
     * Композиция блоков типа (порядок = порядок в акте). Растёт по мере реализации блоков
     * (сейчас — только скалярные блоки инкремента 3a).
     *
     * @return list<BlockKey>
     */
    public function blockKeys(): array
    {
        return match ($this) {
            self::ReferenceArea => [BlockKey::SurfacePrep, BlockKey::Notes],
            self::TrialApplication => [BlockKey::SurfacePrep, BlockKey::Conclusion, BlockKey::Notes],
        };
    }
}
