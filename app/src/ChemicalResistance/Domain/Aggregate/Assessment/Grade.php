<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Domain\Aggregate\Assessment;

enum Grade: string
{
    case R = 'R';
    case NR = 'NR';
    case LR = 'LR';
    case FS = 'FS';
    case NT = 'NT';

    /** «Стойкое» для целей поиска и UI. Единственный источник правды. */
    public function isSuitable(): bool
    {
        return self::R === $this || self::LR === $this;
    }

    /** Человекочитаемая подпись грейда (единый источник для UI). */
    public function label(): string
    {
        return match ($this) {
            self::R => 'Стойкое',
            self::LR => 'Ограниченно',
            self::NR => 'Нестойкое',
            self::FS => 'Спец. условия',
            self::NT => 'Не испытано',
        };
    }
}
