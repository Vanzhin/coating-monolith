<?php

namespace App\Coatings\Domain\Aggregate\CommercialProposal;

/**
 * Типа покрытия по назначению
 */
enum CoatingSystemDurability: string
{
    case LOW = 'до 7 лет';
    case MEDIUM = 'от 7 до 15 лет';
    case HIGH = 'от 15 до 25 лет ';
    case VERY_HIGH = 'более 25 лет ';
}
