<?php

namespace App\Proposals\Domain\Aggregate\Proposal;

use App\Shared\Domain\Trait\EnumToArray;

/**
 * Типа покрытия по назначению
 */
enum CoatingSystemCorrosiveCategory: string
{
    use EnumToArray;

    // Коррозионная среда по ИСО 12944-2
    case C1 = 'C1';
    case C2 = 'C2';
    case C3 = 'C3';
    case C4 = 'C4';
    case C5 = 'C5';
    case CX = 'CX';
    case IM1 = 'IM1';
    case IM2 = 'IM2';
    case IM3 = 'IM3';
    case IM4 = 'IM4';
}
