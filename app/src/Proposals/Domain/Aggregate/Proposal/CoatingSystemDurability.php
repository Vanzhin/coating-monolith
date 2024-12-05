<?php

namespace App\Proposals\Domain\Aggregate\Proposal;

use App\Shared\Domain\Trait\EnumToArray;

/**
 * Типа покрытия по назначению
 */
enum CoatingSystemDurability: string
{
    use EnumToArray;

    case LOW = 'до 7 лет';
    case MEDIUM = 'от 7 до 15 лет';
    case HIGH = 'от 15 до 25 лет ';
    case VERY_HIGH = 'более 25 лет ';
}
