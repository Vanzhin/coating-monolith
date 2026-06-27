<?php
declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

enum EnvironmentType: string
{
    case Atmospheric = 'atmospheric';
    case Immersion   = 'immersion';
    case Special     = 'special';

    /** Читаемое название среды на русском — для UI и сообщений об ошибках. */
    public function title(): string
    {
        return match ($this) {
            self::Atmospheric => 'Атмосферная среда',
            self::Immersion   => 'Погружение',
            self::Special     => 'Спец среды',
        };
    }
}
