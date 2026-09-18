<?php

declare(strict_types=1);

namespace App\Reports\Domain\Aggregate\Report;

/**
 * Вид отчёта — дискриминатор, определяющий композицию блоков (какие блоки и в каком порядке).
 * Сами блоки — переиспользуемые классы+tagged; тип лишь перечисляет их ключи для своего кейса
 * (метод композиции добавляется вместе с реестром блоков).
 */
enum ReportType: string
{
    case PaintSample = 'paint_sample';         // Акт выкрасов эталонного участка
    case TrialApplication = 'trial_application'; // Акт опытного нанесения

    public function label(): string
    {
        return match ($this) {
            self::PaintSample => 'Акт выкрасов эталонного участка',
            self::TrialApplication => 'Акт опытного нанесения',
        };
    }
}
