<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\Enum;

enum ThicknessType: string
{
    case MIC = 'мкм';
    case MM = 'мм';
    case CM = 'см';
}
