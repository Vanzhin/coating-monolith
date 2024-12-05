<?php

namespace App\Proposals\Domain\Aggregate\Proposal;

use App\Shared\Domain\Trait\EnumToArray;

/**
 * Типа покрытия по назначению
 */
enum CoatingSystemApplicationMethod: string
{
    use EnumToArray;

    // Методы нанесения
    case AIR = 'Воздушное нанесение';
    case AIR_OR_AIRLESS = 'Воздушное или безвоздушное нанесение';
    case AIRLESS = 'Безвоздушное нанесение';
    case BRUSH_ROLLER = 'Кисть, валик';
    case ALL = 'Воздушное или безвоздушное нанесение, кисть, валик';
    case TROWEL = 'Мастерок, кельма, шпатель, игольчатый валик';
}
