<?php
declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

enum EnvironmentType: string
{
    case Atmospheric = 'atmospheric';
    case Immersion   = 'immersion';
    case Special     = 'special';
}
