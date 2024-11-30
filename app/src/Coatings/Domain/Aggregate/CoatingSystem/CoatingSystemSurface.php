<?php

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

/**
 * Типа покрытия по назначению
 */
enum CoatingSystemSurface: string
{
    /*
     * Огнезащита.
     */
    case PFP = 'Огнезащита';

    /*
     * Защитное.
     */
    case PROTECTIVE = 'Защитное';

    /*
     * Морское.
     */
    case MARINE = 'Морское';

    /*
     * Специальное.
     */
    case SPECIAL = 'Специальное';
}
