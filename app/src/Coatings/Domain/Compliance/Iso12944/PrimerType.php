<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Iso12944;

enum PrimerType: string
{
    case ZINC_RICH = 'zinc_rich';
    case OTHER = 'other';

    public function title(): string
    {
        return match ($this) {
            self::ZINC_RICH => 'Zn(R)',
            self::OTHER => 'Прочие',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ZINC_RICH => 'Цинкнаполненная грунтовка (≥80% цинка по массе)',
            self::OTHER => 'Прочие типы грунтовок',
        };
    }
}
