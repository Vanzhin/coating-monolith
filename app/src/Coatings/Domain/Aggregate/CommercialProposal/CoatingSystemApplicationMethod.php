<?php

namespace App\Coatings\Domain\Aggregate\CommercialProposal;

/**
 * Типа покрытия по назначению
 */
enum CoatingSystemApplicationMethod: string
{
    // Методы нанесения
    case AIR = 'Воздушное нанесение';
    case AIR_OR_AIRLESS = 'Воздушное или безвоздушное нанесение';
    case AIRLESS = 'Безвоздушное нанесение';
    case BRUSH_ROLLER = 'Кисть, валик';
    case ALL = 'Воздушное или безвоздушное нанесение, кисть, валик';
    case TROWEL = 'Мастерок, кельма, шпатель, игольчатый валик';
}
