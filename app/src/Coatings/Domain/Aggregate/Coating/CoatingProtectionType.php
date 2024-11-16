<?php

namespace App\Coatings\Domain\Aggregate\Coating;

/**
 * Типа покрытия по назначению
 */
enum CoatingProtectionType: string
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
