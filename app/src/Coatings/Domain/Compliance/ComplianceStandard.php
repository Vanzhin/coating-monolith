<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance;

enum ComplianceStandard: string
{
    case ISO_12944 = 'ISO_12944';
    case SP_28 = 'SP_28';

    public function title(): string
    {
        return match ($this) {
            self::ISO_12944 => 'ISO 12944',
            self::SP_28 => 'СП 28.13330',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ISO_12944 => 'ISO 12944 (ГОСТ 34667.5—2021)',
            self::SP_28 => 'СП 28.13330.2017',
        };
    }
}
