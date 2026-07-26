<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

enum ComplianceStandard: string
{
    case ISO_12944 = 'ISO_12944';

    public function title(): string
    {
        return match ($this) {
            self::ISO_12944 => 'ISO 12944',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ISO_12944 => 'ISO 12944 (ГОСТ 34667.5—2021)',
        };
    }
}
