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
}
